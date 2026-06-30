<?php

namespace Deployward\Admin;

final class AdminPage
{
    const SLUG = 'deployward';
    const HOOK_SUFFIX = 'toplevel_page_deployward';

    public function register(): void
    {
        add_menu_page(
            'Deployward',
            'Deployward',
            'manage_options',
            self::SLUG,
            array($this, 'render'),
            'dashicons-update',
            81
        );
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        $theme = \Deployward\Http\PreferencesController::sanitizeTheme(
            get_user_meta(get_current_user_id(), \Deployward\Http\PreferencesController::META_KEY, true)
        );
        echo '<div class="wrap"><div id="deployward-app" '
            . 'data-root="' . esc_attr(esc_url_raw(rest_url('deployward/v1/'))) . '" '
            . 'data-nonce="' . esc_attr(wp_create_nonce('wp_rest')) . '" '
            . 'data-theme="' . esc_attr($theme) . '">'
            . '<noscript>Deployward requires JavaScript.</noscript>'
            . '</div></div>';
    }

    public function enqueue(string $hook): void
    {
        if ($hook !== self::HOOK_SUFFIX) {
            return;
        }
        $base = plugin_dir_url(DEPLOYWARD_FILE);
        wp_enqueue_style('deployward-admin', $base . 'assets/admin.css', array('dashicons'), DEPLOYWARD_VERSION);
        wp_enqueue_script('deployward-admin', $base . 'assets/admin.js', array(), DEPLOYWARD_VERSION, true);
    }
}
