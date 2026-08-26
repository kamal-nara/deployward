<?php

namespace Deployward\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\Cron\CronPoller;
use Deployward\Plugin;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class PluginCronTest extends TestCase
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

    public function test_cron_interval_passthrough_adds_five_minute_schedule(): void
    {
        $schedules = Plugin::cronInterval(array('hourly' => array('interval' => 3600, 'display' => 'Hourly')));

        $this->assertArrayHasKey('hourly', $schedules);
        $this->assertSame(300, $schedules[CronPoller::INTERVAL]['interval']);
    }

    public function test_ensure_scheduled_schedules_when_not_yet_scheduled(): void
    {
        \Brain\Monkey\Functions\when('wp_next_scheduled')->justReturn(false);
        \Brain\Monkey\Functions\when('time')->justReturn(1000000000);
        \Brain\Monkey\Functions\expect('wp_schedule_event')->once()->with(
            1000000000,
            \Deployward\Cron\CronPoller::INTERVAL,
            \Deployward\Cron\CronPoller::HOOK
        );

        Plugin::ensureScheduled();
    }

    public function test_ensure_scheduled_is_idempotent_when_already_scheduled(): void
    {
        \Brain\Monkey\Functions\when('wp_next_scheduled')->justReturn(1234567890);
        \Brain\Monkey\Functions\expect('wp_schedule_event')->never();

        Plugin::ensureScheduled();
    }

    public function test_ensure_token_watch_scheduled_schedules_a_daily_run(): void
    {
        \Brain\Monkey\Functions\when('wp_next_scheduled')->justReturn(false);
        \Brain\Monkey\Functions\when('time')->justReturn(1000000000);
        \Brain\Monkey\Functions\expect('wp_schedule_event')->once()->with(
            1000000000,
            \Deployward\Cron\TokenWatch::INTERVAL,
            \Deployward\Cron\TokenWatch::HOOK
        );

        Plugin::ensureTokenWatchScheduled();
    }

    public function test_ensure_token_watch_scheduled_is_idempotent(): void
    {
        \Brain\Monkey\Functions\when('wp_next_scheduled')->justReturn(1234567890);
        \Brain\Monkey\Functions\expect('wp_schedule_event')->never();

        Plugin::ensureTokenWatchScheduled();
    }

    public function test_deactivate_clears_the_token_watch_too(): void
    {
        $cleared = array();
        \Brain\Monkey\Functions\when('wp_clear_scheduled_hook')->alias(function ($hook) use (&$cleared) {
            $cleared[] = $hook;
        });

        Plugin::deactivate();

        $this->assertContains(CronPoller::HOOK, $cleared);
        $this->assertContains(\Deployward\Cron\TokenWatch::HOOK, $cleared);
        $this->assertContains(\Deployward\Deploy\DeployScheduler::HOOK, $cleared);
    }
}
