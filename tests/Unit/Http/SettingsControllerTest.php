<?php

namespace Deployward\Tests\Unit\Http;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\Config\Settings;
use Deployward\Config\SettingsRepository;
use Deployward\Http\SettingsController;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class SettingsControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private $store = array();

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->store = array('admin_email' => 'admin@example.com');

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
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function controller(): SettingsController
    {
        return new SettingsController(new SettingsRepository());
    }

    public function test_get_returns_the_settings_and_the_admin_email(): void
    {
        $response = $this->controller()->get();

        $this->assertSame(200, $response->status());
        $this->assertSame('', $response->data()['settings']['alert_recipients']);
        $this->assertTrue($response->data()['settings']['email_on_failure']);
        $this->assertSame('admin@example.com', $response->data()['admin_email']);
        $this->assertSame(array('admin@example.com'), $response->data()['recipients']);
    }

    public function test_save_stores_recipients_and_toggles(): void
    {
        $response = $this->controller()->save(array(
            'alert_recipients' => 'ops@nara.ae, kamal@nara.ae',
            'email_on_failure' => true,
            'email_on_success' => false,
        ));

        $this->assertSame(200, $response->status());
        $stored = (new SettingsRepository())->get();
        $this->assertSame('ops@nara.ae, kamal@nara.ae', $stored->alertRecipients());
        $this->assertFalse($stored->shouldEmailOnSuccess());
    }

    public function test_save_rejects_a_bad_address_with_422_naming_it(): void
    {
        $response = $this->controller()->save(array(
            'alert_recipients' => 'ops@nara.ae, oops-at-nara',
            'email_on_failure' => true,
            'email_on_success' => true,
        ));

        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('oops-at-nara', $response->data()['error']);
        $this->assertArrayNotHasKey(SettingsRepository::OPTION, $this->store);
    }

    public function test_send_test_mails_the_configured_recipients_and_reports_them(): void
    {
        (new SettingsRepository())->save(new Settings('ops@nara.ae, kamal@nara.ae', true, true));
        $sent = array();
        Functions\when('wp_mail')->alias(function ($to, $subject, $body) use (&$sent) {
            $sent[] = array('to' => $to, 'subject' => $subject, 'body' => $body);
            return true;
        });

        $response = $this->controller()->sendTest();

        $this->assertSame(200, $response->status());
        $this->assertSame(array('ops@nara.ae', 'kamal@nara.ae'), $response->data()['sent_to']);
        $this->assertCount(1, $sent);
        $this->assertSame('[Deployward] Test alert', $sent[0]['subject']);
        $this->assertSame(array('ops@nara.ae', 'kamal@nara.ae'), $sent[0]['to']);
    }

    public function test_send_test_falls_back_to_the_admin_email(): void
    {
        Functions\when('wp_mail')->justReturn(true);

        $response = $this->controller()->sendTest();

        $this->assertSame(array('admin@example.com'), $response->data()['sent_to']);
    }
}
