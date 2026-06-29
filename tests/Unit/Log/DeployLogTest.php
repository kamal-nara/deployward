<?php

namespace Deployward\Tests\Unit\Log;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\Log\DeployLog;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class DeployLogTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('current_time')->justReturn('2026-06-29 10:00:00');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_record_inserts_mapped_columns(): void
    {
        $wpdb = Mockery::mock();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('insert')->once()->with(
            'wp_deployward_log',
            Mockery::on(function ($data) {
                return $data['deployment_id'] === 'abc'
                    && $data['sha'] === 'deadbeef'
                    && $data['trigger_source'] === 'manual'
                    && $data['status'] === 'success'
                    && $data['created_at'] === '2026-06-29 10:00:00';
            }),
            Mockery::type('array')
        );

        $log = new DeployLog($wpdb);
        $log->record(array(
            'deployment_id' => 'abc',
            'sha' => 'deadbeef',
            'trigger' => 'manual',
            'status' => 'success',
            'message' => 'ok',
        ));
    }

    public function test_recent_uses_prepared_query(): void
    {
        $wpdb = Mockery::mock();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->once()->andReturn('PREPARED');
        $wpdb->shouldReceive('get_results')->once()->with('PREPARED', ARRAY_A)->andReturn(array(array('id' => 1)));

        $log = new DeployLog($wpdb);
        $rows = $log->recent('abc', 10, 0);

        $this->assertSame(array(array('id' => 1)), $rows);
    }
}
