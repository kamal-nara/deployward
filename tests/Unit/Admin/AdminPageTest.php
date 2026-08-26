<?php

namespace Deployward\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\Admin\AdminPage;
use PHPUnit\Framework\TestCase;

final class AdminPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_current_user_id')->justReturn(1);
        Functions\when('get_user_meta')->justReturn('dark');
        Functions\when('rest_url')->alias(function ($path) {
            return 'https://nara.local/wp-json/' . $path;
        });
        Functions\when('wp_create_nonce')->justReturn('nonce123');
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_url_raw')->returnArg();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function render(): string
    {
        ob_start();
        (new AdminPage())->render();

        return (string) ob_get_clean();
    }

    public function test_render_closes_the_admin_header_before_the_app_container(): void
    {
        $markup = $this->render();

        $headerEnd = strpos($markup, 'wp-header-end');
        $app = strpos($markup, 'id="deployward-app"');

        $this->assertNotFalse($headerEnd, 'the markup must carry a wp-header-end marker');
        $this->assertNotFalse($app);
        $this->assertLessThan($app, $headerEnd, 'wp-header-end must come before the app container');
    }

    public function test_render_carries_the_rest_root_nonce_and_theme(): void
    {
        $markup = $this->render();

        $this->assertStringContainsString('data-root="https://nara.local/wp-json/deployward/v1/"', $markup);
        $this->assertStringContainsString('data-nonce="nonce123"', $markup);
        $this->assertStringContainsString('data-theme="dark"', $markup);
    }

    public function test_render_writes_nothing_without_the_capability(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        $this->assertSame('', $this->render());
    }
}
