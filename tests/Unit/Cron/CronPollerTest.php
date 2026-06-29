<?php

namespace Deployward\Tests\Unit\Cron;

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

    private function deployment(string $lastSha): Deployment
    {
        return Deployment::fromArray(array(
            'id' => 'dw_abc', 'repo' => 'o/r', 'branch' => 'main', 'visibility' => 'public',
            'target_type' => 'plugin', 'target_slug' => 'sample', 'token' => '', 'webhook_secret' => 's',
            'last_deployed_sha' => $lastSha,
        ));
    }

    public function test_queues_deploy_when_sha_changed(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('all')->andReturn(array('dw_abc' => $this->deployment('oldsha')));
        $github = Mockery::mock(GitHubClientInterface::class);
        $github->shouldReceive('resolveSha')->with('o/r', 'main', null)->andReturn(Result::ok('newsha'));
        $scheduler = Mockery::mock(DeploySchedulerInterface::class);
        $scheduler->shouldReceive('schedule')->once()->with('dw_abc', 'cron', false);

        (new CronPoller($repo, $github, $scheduler))->poll();
    }

    public function test_does_not_queue_when_sha_unchanged(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('all')->andReturn(array('dw_abc' => $this->deployment('samesha')));
        $github = Mockery::mock(GitHubClientInterface::class);
        $github->shouldReceive('resolveSha')->andReturn(Result::ok('samesha'));
        $scheduler = Mockery::mock(DeploySchedulerInterface::class);
        $scheduler->shouldNotReceive('schedule');

        (new CronPoller($repo, $github, $scheduler))->poll();
    }

    public function test_skips_when_sha_cannot_be_resolved(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('all')->andReturn(array('dw_abc' => $this->deployment('oldsha')));
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
}
