<?php

namespace Deployward;

use Deployward\Admin\AdminPage;
use Deployward\Cli\DeployCommand;
use Deployward\Cron\CronPoller;
use Deployward\Deploy\DeployScheduler;
use Deployward\Http\RestController;
use Deployward\Http\RestRoutes;
use Deployward\Http\WebhookController;
use Deployward\Log\DeployLog;
use Deployward\Security\SignatureVerifier;

final class Plugin
{
    public static function activate(): void
    {
        global $wpdb;
        $log = new DeployLog($wpdb);
        $log->installTable();

        add_filter('cron_schedules', array(__CLASS__, 'cronInterval'));
        self::ensureScheduled();
    }

    public static function ensureScheduled(): void
    {
        if (! wp_next_scheduled(CronPoller::HOOK)) {
            wp_schedule_event(time(), CronPoller::INTERVAL, CronPoller::HOOK);
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(CronPoller::HOOK);
        wp_clear_scheduled_hook(DeployScheduler::HOOK);
    }

    public static function boot(): void
    {
        global $wpdb;
        $container = new Container($wpdb);

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('deployward', self::makeCommand());
        }

        $scheduler = new DeployScheduler($container);
        $poller = new CronPoller($container->repository(), $container->github(), $scheduler);

        add_filter('cron_schedules', array(__CLASS__, 'cronInterval'));
        self::ensureScheduled();
        add_action(DeployScheduler::HOOK, array($scheduler, 'run'), 10, 3);
        add_action(CronPoller::HOOK, array($poller, 'poll'));

        $routes = new RestRoutes(
            new RestController(
                $container->repository(),
                $container->deployer(),
                $container->log(),
                $container->github()
            ),
            new WebhookController($container->repository(), new SignatureVerifier(), $scheduler)
        );
        add_action('rest_api_init', array($routes, 'register'));

        $page = new AdminPage();
        add_action('admin_menu', array($page, 'register'));
        add_action('admin_enqueue_scripts', array($page, 'enqueue'));
    }

    public static function cronInterval(array $schedules): array
    {
        return CronPoller::addInterval($schedules);
    }

    public static function makeCommand(): DeployCommand
    {
        global $wpdb;
        $container = new Container($wpdb);

        return new DeployCommand($container->repository(), $container->deployer(), $container->log());
    }
}
