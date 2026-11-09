<?php

namespace Deployward\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\Cron\CronPoller;
use Deployward\Plugin;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class PluginCronTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    const ADMIN_URL = 'https://nara.local/wp-admin/admin.php?page=deployward';

    /** @var array */
    private $store = array();
    /** @var array */
    private $sent = array();

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->store = array();
        $this->sent = array();
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

    private function bootEnvironment(): void
    {
        foreach (array('AUTH_KEY' => str_repeat('a', 64), 'AUTH_SALT' => str_repeat('b', 64)) as $name => $value) {
            if (! defined($name)) {
                define($name, $value);
            }
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

        $this->store = array(
            'admin_email' => 'admin@example.com',
            \Deployward\Config\SettingsRepository::OPTION => array(
                'alert_recipients' => 'ops@nara.ae',
                'email_on_failure' => false,
                'email_on_success' => false,
            ),
            \Deployward\Config\DeploymentRepository::OPTION => array(
                'dw_expired' => array(
                    'id' => 'dw_expired',
                    'repo' => 'Nara-IT/nara-core',
                    'branch' => 'main',
                    'visibility' => 'private',
                    'target_type' => 'plugin',
                    'target_slug' => 'nara-core',
                    'token' => '',
                    'webhook_secret' => 'whsec',
                    'last_deployed_sha' => '',
                    'token_last4' => 'abcd',
                    'token_expires_at' => '2020-01-01T00:00:00+00:00',
                    'token_checked_at' => '',
                    'token_alerted' => '',
                ),
            ),
        );

        Functions\when('get_option')->alias(function ($key, $default = false) {
            return array_key_exists($key, $this->store) ? $this->store[$key] : $default;
        });
        Functions\when('update_option')->alias(function ($key, $value, $autoload = true) {
            $this->store[$key] = $value;
            return true;
        });
        Functions\when('is_email')->alias(function ($value) {
            return (bool) filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : false;
        });
        Functions\when('wp_mail')->alias(function ($to, $subject, $body) {
            $this->sent[] = array('to' => $to, 'subject' => $subject, 'body' => $body);
            return true;
        });
        Functions\when('wp_date')->alias(function ($format, $timestamp = null, $timezone = null) {
            return gmdate($format, $timestamp === null ? 0 : (int) $timestamp);
        });
        Functions\when('wp_next_scheduled')->justReturn(1234567890);
        Functions\when('wp_upload_dir')->justReturn(array('basedir' => sys_get_temp_dir() . '/uploads'));
        Functions\when('get_temp_dir')->justReturn(sys_get_temp_dir() . '/');
        Functions\when('get_theme_root')->justReturn(sys_get_temp_dir() . '/themes');
        Functions\when('home_url')->justReturn('https://nara.local');
        Functions\when('admin_url')->justReturn(self::ADMIN_URL);
        Functions\when('trailingslashit')->alias(function ($path) {
            return rtrim($path, '/') . '/';
        });

        Functions\when('wp_cache_delete')->justReturn(true);

        global $wpdb;
        $wpdb = new \Deployward\Tests\Support\FakeWpdb($this->store);
    }

    private function captureHook(string $hook): array
    {
        $captured = array();
        \Brain\Monkey\Actions\expectAdded($hook)->once()->whenHappen(
            function ($callback, $priority = 10, $acceptedArgs = 1) use (&$captured) {
                $captured = array('callback' => $callback, 'accepted_args' => (int) $acceptedArgs);
            }
        );

        return $captured;
    }

    /** Mirrors WP_Hook::apply_filters, which slices the argument list to accepted_args. */
    private static function fireWithNoArguments(array $captured)
    {
        return call_user_func_array(
            $captured['callback'],
            array_slice(array(''), 0, $captured['accepted_args'])
        );
    }

    public function test_the_token_watch_hook_runs_when_wp_cron_fires_it_without_arguments(): void
    {
        $this->bootEnvironment();
        $captured = array();
        \Brain\Monkey\Actions\expectAdded(\Deployward\Cron\TokenWatch::HOOK)->once()->whenHappen(
            function ($callback, $priority = 10, $acceptedArgs = 1) use (&$captured) {
                $captured = array('callback' => $callback, 'accepted_args' => (int) $acceptedArgs);
            }
        );

        Plugin::boot();
        self::fireWithNoArguments($captured);

        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString('expired', $this->sent[0]['subject']);
        $this->assertStringContainsString('Nara-IT/nara-core', $this->sent[0]['subject']);
        $this->assertStringContainsString(
            'expired',
            $this->store[\Deployward\Config\DeploymentRepository::OPTION]['dw_expired']['token_alerted']
        );
    }

    public function test_booting_never_touches_the_directory_suffix_so_a_storage_fault_cannot_break_a_request(): void
    {
        $this->bootEnvironment();
        global $wpdb;
        $wpdb->isFailing = true;

        Plugin::boot();

        $this->assertArrayNotHasKey(\Deployward\Deploy\StorageNames::OPTION, $this->store);
        $this->assertSame(0, $wpdb->queries);
    }

    public function test_the_poll_hook_runs_when_wp_cron_fires_it_without_arguments(): void
    {
        $this->bootEnvironment();
        $captured = array();
        \Brain\Monkey\Actions\expectAdded(CronPoller::HOOK)->once()->whenHappen(
            function ($callback, $priority = 10, $acceptedArgs = 1) use (&$captured) {
                $captured = array('callback' => $callback, 'accepted_args' => (int) $acceptedArgs);
            }
        );

        Plugin::boot();
        self::fireWithNoArguments($captured);

        $this->assertArrayHasKey(CronPoller::LAST_POLLS_OPTION, $this->store);
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
