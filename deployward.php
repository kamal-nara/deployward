<?php
/**
 * Plugin Name: Deployward
 * Description: Safely auto-deploys selected plugins and themes from GitHub, with validation, atomic swap, health check, and auto-rollback.
 * Version:     0.4.1
 * Author:      Kamal Ahmed
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * License:     GPL-2.0-or-later
 * Text Domain: deployward
 */

if (! defined('ABSPATH')) {
    exit;
}

define('DEPLOYWARD_VERSION', '0.4.1');
define('DEPLOYWARD_FILE', __FILE__);
define('DEPLOYWARD_PATH', plugin_dir_path(__FILE__));
define('DEPLOYWARD_SLUG', 'deployward');

require_once DEPLOYWARD_PATH . 'src/Autoloader.php';
\Deployward\Autoloader::register(DEPLOYWARD_PATH . 'src');

register_activation_hook(__FILE__, array('\\Deployward\\Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('\\Deployward\\Plugin', 'deactivate'));
add_action('plugins_loaded', array('\\Deployward\\Plugin', 'boot'));
