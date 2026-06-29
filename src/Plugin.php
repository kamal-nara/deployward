<?php

namespace Deployward;

use Deployward\Cli\DeployCommand;
use Deployward\Log\DeployLog;

final class Plugin
{
    public static function activate(): void
    {
        global $wpdb;
        $log = new DeployLog($wpdb);
        $log->installTable();
    }

    public static function boot(): void
    {
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('deployward', self::makeCommand());
        }
    }

    public static function makeCommand(): DeployCommand
    {
        global $wpdb;
        $container = new Container($wpdb);

        return new DeployCommand($container->repository(), $container->deployer(), $container->log());
    }
}
