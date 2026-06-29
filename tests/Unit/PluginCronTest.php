<?php

namespace Deployward\Tests\Unit;

use Deployward\Cron\CronPoller;
use Deployward\Plugin;
use PHPUnit\Framework\TestCase;

final class PluginCronTest extends TestCase
{
    public function test_cron_interval_passthrough_adds_five_minute_schedule(): void
    {
        $schedules = Plugin::cronInterval(array('hourly' => array('interval' => 3600, 'display' => 'Hourly')));

        $this->assertArrayHasKey('hourly', $schedules);
        $this->assertSame(300, $schedules[CronPoller::INTERVAL]['interval']);
    }
}
