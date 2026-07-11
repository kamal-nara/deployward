<?php

namespace Deployward\Tests\Unit\Cron;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\Config\Deployment;
use Deployward\Config\DeploymentRepositoryInterface;
use Deployward\Cron\CronPoller;
use Deployward\Deploy\DeploySchedulerInterface;
use Deployward\GitHub\GitHubClientInterface;
use Deployward\Support\Result;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class CronPollerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function deployment(array $overrides = array()): Deployment
    {
        return Deployment::fromArray(array_merge(array(
            'id' => 'dw_abc', 'repo' => 'o/r', 'branch' => 'main', 'visibility' => 'public',
            'target_type' => 'plugin', 'target_slug' => 'sample', 'token' => '', 'webhook_secret' => 's',
            'last_deployed_sha' => '',
        ), $overrides));
    }

    public function test_queues_deploy_when_sha_changed(): void
    {
        Functions\when('get_option')->justReturn(array());
        Functions\when('update_option')->justReturn(true);

        $deployment = $this->deployment(array('auto_deploy' => true, 'last_deployed_sha' => 'oldsha'));
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('all')->andReturn(array('dw_abc' => $deployment));
        $github = Mockery::mock(GitHubClientInterface::class);
        $github->shouldReceive('resolveSha')->with('o/r', 'main', null)->andReturn(Result::ok('newsha'));
        $scheduler = Mockery::mock(DeploySchedulerInterface::class);
        $scheduler->shouldReceive('schedule')->once()->with('dw_abc', 'cron', false);

        (new CronPoller($repo, $github, $scheduler))->poll();
    }

    public function test_does_not_queue_when_sha_unchanged(): void
    {
        Functions\when('get_option')->justReturn(array());
        Functions\when('update_option')->justReturn(true);

        $deployment = $this->deployment(array('auto_deploy' => true, 'last_deployed_sha' => 'samesha'));
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('all')->andReturn(array('dw_abc' => $deployment));
        $github = Mockery::mock(GitHubClientInterface::class);
        $github->shouldReceive('resolveSha')->andReturn(Result::ok('samesha'));
        $scheduler = Mockery::mock(DeploySchedulerInterface::class);
        $scheduler->shouldNotReceive('schedule');

        (new CronPoller($repo, $github, $scheduler))->poll();
    }

    public function test_skips_when_sha_cannot_be_resolved(): void
    {
        Functions\when('get_option')->justReturn(array());
        Functions\when('update_option')->justReturn(true);

        $deployment = $this->deployment(array('auto_deploy' => true, 'last_deployed_sha' => 'oldsha'));
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('all')->andReturn(array('dw_abc' => $deployment));
        $github = Mockery::mock(GitHubClientInterface::class);
        $github->shouldReceive('resolveSha')->andReturn(Result::fail('GitHub HTTP 500'));
        $scheduler = Mockery::mock(DeploySchedulerInterface::class);
        $scheduler->shouldNotReceive('schedule');

        (new CronPoller($repo, $github, $scheduler))->poll();
    }

    public function test_add_interval_registers_five_minutes(): void
    {
        $schedules = CronPoller::addInterval(array());

        $this->assertSame(300, $schedules[CronPoller::INTERVAL]['interval']);
    }

    public function test_disabled_deployment_is_never_polled_and_pruned_from_map(): void
    {
        $deployment = $this->deployment(array('id' => 'dw_abc'));
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('all')->andReturn(array('dw_abc' => $deployment));
        $github = Mockery::mock(GitHubClientInterface::class);
        $github->shouldNotReceive('resolveSha');
        $scheduler = Mockery::mock(DeploySchedulerInterface::class);
        $scheduler->shouldNotReceive('schedule');

        Functions\expect('get_option')->once()
            ->with(CronPoller::LAST_POLLS_OPTION, array())
            ->andReturn(array('dw_abc' => 123456789));
        Functions\expect('update_option')->once()->with(
            CronPoller::LAST_POLLS_OPTION,
            Mockery::on(function ($payload) {
                return is_array($payload) && ! array_key_exists('dw_abc', $payload);
            }),
            false
        );

        (new CronPoller($repo, $github, $scheduler))->poll();
    }

    public function test_enabled_and_never_polled_is_polled_and_timestamp_recorded(): void
    {
        $now = 5000000000;
        Functions\when('time')->justReturn($now);

        $deployment = $this->deployment(array(
            'id' => 'dw_b', 'auto_deploy' => true, 'poll_interval' => 5, 'last_deployed_sha' => 'samesha',
        ));
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('all')->andReturn(array('dw_b' => $deployment));
        $github = Mockery::mock(GitHubClientInterface::class);
        $github->shouldReceive('resolveSha')->once()->andReturn(Result::ok('samesha'));
        $scheduler = Mockery::mock(DeploySchedulerInterface::class);
        $scheduler->shouldNotReceive('schedule');

        Functions\expect('get_option')->once()
            ->with(CronPoller::LAST_POLLS_OPTION, array())
            ->andReturn(array());
        Functions\expect('update_option')->once()
            ->with(CronPoller::LAST_POLLS_OPTION, array('dw_b' => $now), false);

        (new CronPoller($repo, $github, $scheduler))->poll();
    }

    public function test_enabled_but_not_due_two_minutes_in_is_skipped_and_timestamp_carried(): void
    {
        $now = 5000000000;
        Functions\when('time')->justReturn($now);

        $deployment = $this->deployment(array(
            'id' => 'dw_c', 'auto_deploy' => true, 'poll_interval' => 15,
        ));
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('all')->andReturn(array('dw_c' => $deployment));
        $github = Mockery::mock(GitHubClientInterface::class);
        $github->shouldNotReceive('resolveSha');
        $scheduler = Mockery::mock(DeploySchedulerInterface::class);
        $scheduler->shouldNotReceive('schedule');

        Functions\expect('get_option')->once()
            ->with(CronPoller::LAST_POLLS_OPTION, array())
            ->andReturn(array('dw_c' => $now - 120));
        Functions\expect('update_option')->once()
            ->with(CronPoller::LAST_POLLS_OPTION, array('dw_c' => $now - 120), false);

        (new CronPoller($repo, $github, $scheduler))->poll();
    }

    public function test_enabled_and_due_sixteen_minutes_in_is_polled(): void
    {
        $now = 5000000000;
        Functions\when('time')->justReturn($now);

        $deployment = $this->deployment(array(
            'id' => 'dw_d', 'auto_deploy' => true, 'poll_interval' => 15, 'last_deployed_sha' => 'samesha',
        ));
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('all')->andReturn(array('dw_d' => $deployment));
        $github = Mockery::mock(GitHubClientInterface::class);
        $github->shouldReceive('resolveSha')->once()->andReturn(Result::ok('samesha'));
        $scheduler = Mockery::mock(DeploySchedulerInterface::class);
        $scheduler->shouldNotReceive('schedule');

        Functions\expect('get_option')->once()
            ->with(CronPoller::LAST_POLLS_OPTION, array())
            ->andReturn(array('dw_d' => $now - 960));
        Functions\expect('update_option')->once()
            ->with(CronPoller::LAST_POLLS_OPTION, array('dw_d' => $now), false);

        (new CronPoller($repo, $github, $scheduler))->poll();
    }
}
