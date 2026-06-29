<?php

namespace Deployward\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\Config\DeploymentRepositoryInterface;
use Deployward\Container;
use Deployward\Deploy\DeployerInterface;
use Deployward\GitHub\GitHubClientInterface;
use Deployward\Log\DeployLogInterface;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class ContainerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        if (! defined('AUTH_KEY')) {
            define('AUTH_KEY', str_repeat('a', 64));
        }
        if (! defined('AUTH_SALT')) {
            define('AUTH_SALT', str_repeat('b', 64));
        }
        if (! defined('WP_PLUGIN_DIR')) {
            define('WP_PLUGIN_DIR', sys_get_temp_dir() . '/plugins');
        }
        if (! defined('WP_CONTENT_DIR')) {
            define('WP_CONTENT_DIR', sys_get_temp_dir());
        }
        if (! defined('ABSPATH')) {
            define('ABSPATH', sys_get_temp_dir() . '/');
        }
        if (! defined('DEPLOYWARD_SLUG')) {
            define('DEPLOYWARD_SLUG', 'deployward');
        }
        Functions\when('wp_upload_dir')->justReturn(array('basedir' => sys_get_temp_dir() . '/uploads'));
        Functions\when('get_temp_dir')->justReturn(sys_get_temp_dir() . '/');
        Functions\when('get_theme_root')->justReturn(sys_get_temp_dir() . '/themes');
        Functions\when('home_url')->justReturn('https://nara.local/');
        Functions\when('get_option')->justReturn('admin@example.com');
        Functions\when('trailingslashit')->alias(function ($p) {
            return rtrim($p, '/') . '/';
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_builds_the_engine_collaborators(): void
    {
        $wpdb = Mockery::mock();
        $wpdb->prefix = 'wp_';
        $container = new Container($wpdb);

        $this->assertInstanceOf(DeploymentRepositoryInterface::class, $container->repository());
        $this->assertInstanceOf(DeployLogInterface::class, $container->log());
        $this->assertInstanceOf(GitHubClientInterface::class, $container->github());
        $this->assertInstanceOf(DeployerInterface::class, $container->deployer());
    }
}
