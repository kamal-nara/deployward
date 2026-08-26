<?php

namespace Deployward\Tests\Unit\Cron;

use Brain\Monkey;
use Deployward\Config\Deployment;
use Deployward\Config\DeploymentRepositoryInterface;
use Deployward\Cron\TokenWatch;
use Deployward\Notify\NotifierInterface;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class TokenWatchTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    const ADMIN_URL = 'https://nara.local/wp-admin/admin.php?page=deployward';

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

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-08-27T09:00:00+00:00');
    }

    private function deployment(array $overrides = array()): Deployment
    {
        return Deployment::fromArray(array_merge(array(
            'id' => 'dw_1',
            'repo' => 'Nara-IT/nara-core',
            'branch' => 'main',
            'visibility' => 'private',
            'target_type' => 'plugin',
            'target_slug' => 'nara-core',
            'token' => 'ghp_x',
            'webhook_secret' => 'whsec',
        ), $overrides));
    }

    private function watch($repository, $notifier): TokenWatch
    {
        return new TokenWatch($repository, $notifier, self::ADMIN_URL);
    }

    private function repositoryWith(Deployment $deployment)
    {
        $repository = Mockery::mock(DeploymentRepositoryInterface::class);
        $repository->shouldReceive('all')->andReturn(array($deployment->id() => $deployment));

        return $repository;
    }

    public function test_warns_fourteen_days_before_the_expiry_and_records_the_stage(): void
    {
        $repository = $this->repositoryWith($this->deployment(array('token_expires_at' => '2026-09-05T09:00:00+00:00')));
        $repository->shouldNotReceive('save');
        $repository->shouldReceive('recordTokenState')->once()->with('dw_1', array('token_alerted' => '14d'));
        $notifier = Mockery::mock(NotifierInterface::class);
        $notifier->shouldReceive('notify')->once()->with(
            Mockery::on(function ($subject) {
                return strpos($subject, 'Nara-IT/nara-core') !== false && strpos($subject, '5 Sep 2026') !== false;
            }),
            Mockery::on(function ($body) {
                return strpos($body, self::ADMIN_URL) !== false;
            }),
            NotifierInterface::KIND_ALERT
        );

        $this->watch($repository, $notifier)->run($this->now());
    }

    public function test_does_not_warn_twice_for_the_same_stage(): void
    {
        $repository = $this->repositoryWith($this->deployment(array(
            'token_expires_at' => '2026-09-05T09:00:00+00:00',
            'token_alerted' => '14d',
        )));
        $repository->shouldNotReceive('save');
        $repository->shouldNotReceive('recordTokenState');
        $notifier = Mockery::mock(NotifierInterface::class);
        $notifier->shouldNotReceive('notify');

        $this->watch($repository, $notifier)->run($this->now());
    }

    public function test_warns_one_day_before_even_when_the_fourteen_day_warning_went_out(): void
    {
        $repository = $this->repositoryWith($this->deployment(array(
            'token_expires_at' => '2026-08-27T20:00:00+00:00',
            'token_alerted' => '14d',
        )));
        $repository->shouldNotReceive('save');
        $repository->shouldReceive('recordTokenState')->once()->with('dw_1', array('token_alerted' => '14d,1d'));
        $notifier = Mockery::mock(NotifierInterface::class);
        $notifier->shouldReceive('notify')->once();

        $this->watch($repository, $notifier)->run($this->now());
    }

    public function test_warns_once_after_the_token_has_expired(): void
    {
        $repository = $this->repositoryWith($this->deployment(array(
            'token_expires_at' => '2026-08-20T09:00:00+00:00',
            'token_alerted' => '14d,1d',
        )));
        $repository->shouldNotReceive('save');
        $repository->shouldReceive('recordTokenState')->once()->with('dw_1', array('token_alerted' => '14d,1d,expired'));
        $notifier = Mockery::mock(NotifierInterface::class);
        $notifier->shouldReceive('notify')->once()->with(
            Mockery::on(function ($subject) {
                return strpos($subject, 'expired') !== false;
            }),
            Mockery::any(),
            NotifierInterface::KIND_ALERT
        );

        $this->watch($repository, $notifier)->run($this->now());
    }

    public function test_stays_quiet_well_before_the_expiry(): void
    {
        $repository = $this->repositoryWith($this->deployment(array('token_expires_at' => '2026-12-01T09:00:00+00:00')));
        $repository->shouldNotReceive('save');
        $repository->shouldNotReceive('recordTokenState');
        $notifier = Mockery::mock(NotifierInterface::class);
        $notifier->shouldNotReceive('notify');

        $this->watch($repository, $notifier)->run($this->now());
    }

    public function test_ignores_deployments_without_a_known_expiry(): void
    {
        $repository = $this->repositoryWith($this->deployment());
        $repository->shouldNotReceive('save');
        $repository->shouldNotReceive('recordTokenState');
        $notifier = Mockery::mock(NotifierInterface::class);
        $notifier->shouldNotReceive('notify');

        $this->watch($repository, $notifier)->run($this->now());
    }

    public function test_ignores_public_deployments(): void
    {
        $repository = $this->repositoryWith($this->deployment(array(
            'visibility' => 'public',
            'token' => '',
            'token_expires_at' => '2026-09-05T09:00:00+00:00',
        )));
        $repository->shouldNotReceive('save');
        $repository->shouldNotReceive('recordTokenState');
        $notifier = Mockery::mock(NotifierInterface::class);
        $notifier->shouldNotReceive('notify');

        $this->watch($repository, $notifier)->run($this->now());
    }

    public function test_a_replaced_token_starts_the_warnings_over(): void
    {
        $repository = $this->repositoryWith($this->deployment(array(
            'token_expires_at' => '2026-09-05T09:00:00+00:00',
            'token_alerted' => '',
        )));
        $repository->shouldNotReceive('save');
        $repository->shouldReceive('recordTokenState')->once();
        $notifier = Mockery::mock(NotifierInterface::class);
        $notifier->shouldReceive('notify')->once();

        $this->watch($repository, $notifier)->run($this->now());
    }
}
