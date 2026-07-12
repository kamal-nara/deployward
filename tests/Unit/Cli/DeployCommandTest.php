<?php

namespace Deployward\Tests\Unit\Cli;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\Cli\DeployCommand;
use Deployward\Config\Deployment;
use Deployward\Config\DeploymentRepositoryInterface;
use Deployward\Deploy\DeployerInterface;
use Deployward\Log\DeployLogInterface;
use Deployward\Support\Result;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class DeployCommandTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('sanitize_key')->returnArg();
        Functions\when('wp_generate_password')->justReturn('generated');
        if (! class_exists('WP_CLI')) {
            eval('class WP_CLI { public static $errors = array(); public static $success = array(); public static function error($m) { self::$errors[] = $m; throw new \Exception($m); } public static function success($m) { self::$success[] = $m; } public static function log($m) {} public static function line($m) {} }');
        }
        \WP_CLI::$errors = array();
        \WP_CLI::$success = array();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_add_saves_a_valid_deployment(): void
    {
        $repository = Mockery::mock(DeploymentRepositoryInterface::class);
        $repository->shouldReceive('save')->once()->with(Mockery::type(Deployment::class));
        $command = new DeployCommand($repository, Mockery::mock(DeployerInterface::class), Mockery::mock(DeployLogInterface::class));

        $command->add(array(), array(
            'id' => 'abc',
            'repo' => 'Nara-IT/nara-core',
            'branch' => 'main',
            'visibility' => 'private',
            'type' => 'plugin',
            'slug' => 'nara-core',
            'token' => 'ghp_x',
        ));

        $this->assertNotEmpty(\WP_CLI::$success);
    }

    public function test_add_with_webhook_deploy_flag_only_saves_push_enabled_deployment(): void
    {
        $captured = null;
        $repository = Mockery::mock(DeploymentRepositoryInterface::class);
        $repository->shouldReceive('save')->once()->with(Mockery::on(function ($d) use (&$captured) {
            $captured = $d;
            return true;
        }));
        $command = new DeployCommand($repository, Mockery::mock(DeployerInterface::class), Mockery::mock(DeployLogInterface::class));

        $command->add(array(), array(
            'id' => 'abc',
            'repo' => 'Nara-IT/nara-core',
            'branch' => 'main',
            'visibility' => 'public',
            'type' => 'plugin',
            'slug' => 'nara-core',
            'webhook-deploy' => true,
        ));

        $this->assertTrue($captured->deploysOnPush());
        $this->assertFalse($captured->deploysOnSchedule());
    }

    public function test_add_with_both_deploy_flags_and_poll_interval_saves_both_enabled_deployment(): void
    {
        $captured = null;
        $repository = Mockery::mock(DeploymentRepositoryInterface::class);
        $repository->shouldReceive('save')->once()->with(Mockery::on(function ($d) use (&$captured) {
            $captured = $d;
            return true;
        }));
        $command = new DeployCommand($repository, Mockery::mock(DeployerInterface::class), Mockery::mock(DeployLogInterface::class));

        $command->add(array(), array(
            'id' => 'abc',
            'repo' => 'Nara-IT/nara-core',
            'branch' => 'main',
            'visibility' => 'public',
            'type' => 'plugin',
            'slug' => 'nara-core',
            'webhook-deploy' => true,
            'poll-deploy' => true,
            'poll-interval' => '30',
        ));

        $this->assertTrue($captured->deploysOnPush());
        $this->assertTrue($captured->deploysOnSchedule());
        $this->assertSame(30, $captured->pollInterval());
    }

    public function test_add_without_deploy_trigger_flags_saves_manual_deployment(): void
    {
        $captured = null;
        $repository = Mockery::mock(DeploymentRepositoryInterface::class);
        $repository->shouldReceive('save')->once()->with(Mockery::on(function ($d) use (&$captured) {
            $captured = $d;
            return true;
        }));
        $command = new DeployCommand($repository, Mockery::mock(DeployerInterface::class), Mockery::mock(DeployLogInterface::class));

        $command->add(array(), array(
            'id' => 'abc',
            'repo' => 'Nara-IT/nara-core',
            'branch' => 'main',
            'visibility' => 'public',
            'type' => 'plugin',
            'slug' => 'nara-core',
        ));

        $this->assertFalse($captured->deploysOnPush());
        $this->assertFalse($captured->deploysOnSchedule());
        $this->assertSame(5, $captured->pollInterval());
    }

    public function test_deploy_invokes_deployer_for_known_id(): void
    {
        $deployment = Deployment::fromArray(array(
            'id' => 'abc', 'repo' => 'Nara-IT/nara-core', 'branch' => 'main',
            'visibility' => 'public', 'target_type' => 'plugin', 'target_slug' => 'nara-core',
        ));
        $repository = Mockery::mock(DeploymentRepositoryInterface::class);
        $repository->shouldReceive('find')->with('abc')->andReturn($deployment);
        $deployer = Mockery::mock(DeployerInterface::class);
        $deployer->shouldReceive('deploy')->once()->with(Mockery::type(Deployment::class), 'manual', false)
            ->andReturn(Result::ok('newsha', 'Deployed newsha'));
        $command = new DeployCommand($repository, $deployer, Mockery::mock(DeployLogInterface::class));

        $command->deploy(array('abc'), array());

        $this->assertNotEmpty(\WP_CLI::$success);
    }
}
