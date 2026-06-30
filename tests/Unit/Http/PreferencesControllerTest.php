<?php

namespace Deployward\Tests\Unit\Http;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\Http\PreferencesController;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class PreferencesControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('get_current_user_id')->justReturn(1);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_get_returns_saved_dark_theme(): void
    {
        Functions\when('get_user_meta')
            ->justReturn('dark');

        $response = (new PreferencesController())->get();

        $this->assertSame(200, $response->status());
        $this->assertSame('dark', $response->data()['theme']);
    }

    public function test_get_defaults_to_light_when_meta_empty(): void
    {
        Functions\when('get_user_meta')
            ->justReturn('');

        $response = (new PreferencesController())->get();

        $this->assertSame(200, $response->status());
        $this->assertSame('light', $response->data()['theme']);
    }

    public function test_save_dark_calls_update_user_meta_and_returns_200(): void
    {
        Functions\expect('update_user_meta')
            ->once()
            ->with(1, PreferencesController::META_KEY, 'dark');

        $response = (new PreferencesController())->save(array('theme' => 'dark'));

        $this->assertSame(200, $response->status());
        $this->assertSame('dark', $response->data()['theme']);
    }

    public function test_save_invalid_theme_returns_422_and_does_not_call_update(): void
    {
        Functions\expect('update_user_meta')->never();

        $response = (new PreferencesController())->save(array('theme' => 'purple'));

        $this->assertSame(422, $response->status());
        $this->assertArrayHasKey('error', $response->data());
    }

    public function test_sanitize_theme_maps_dark_to_dark(): void
    {
        $this->assertSame('dark', PreferencesController::sanitizeTheme('dark'));
    }

    public function test_sanitize_theme_maps_other_values_to_light(): void
    {
        $this->assertSame('light', PreferencesController::sanitizeTheme(''));
        $this->assertSame('light', PreferencesController::sanitizeTheme('purple'));
        $this->assertSame('light', PreferencesController::sanitizeTheme(null));
        $this->assertSame('light', PreferencesController::sanitizeTheme(false));
    }
}
