<?php

namespace Deployward\Tests\Unit\Http;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\Config\Settings;
use Deployward\Config\SettingsRepository;
use Deployward\Http\SettingsController;
use Deployward\Security\KeyRing;
use Deployward\Tests\Support\InMemoryKeyStore;
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

    private function controller(?KeyRing $keys = null): SettingsController
    {
        $ring = $keys !== null ? $keys : KeyRing::fromMaterial(null, 'salts', new InMemoryKeyStore());

        return new SettingsController(new SettingsRepository(), $ring);
    }

    public function test_get_reports_the_own_key_as_the_token_encryption_source(): void
    {
        $response = $this->controller()->get();

        $this->assertSame(array(
            'source' => 'own',
            'summary' => "Deployward's own key (only changes when an admin runs wp deployward rotate-key)",
            'problem' => '',
        ), $response->data()['token_encryption']);
    }

    public function test_get_reports_the_wp_config_key_when_the_constant_is_the_primary(): void
    {
        $keys = KeyRing::fromMaterial(str_repeat('c', 40), 'salts', new InMemoryKeyStore());

        $info = $this->controller($keys)->get()->data()['token_encryption'];

        $this->assertSame('wp-config', $info['source']);
        $this->assertSame('DEPLOYWARD_ENCRYPTION_KEY in wp-config.php', $info['summary']);
        $this->assertSame('', $info['problem']);
    }

    public function test_get_reports_a_too_short_constant_as_a_problem_and_keeps_the_own_key(): void
    {
        $keys = KeyRing::fromMaterial('too-short-secret', 'salts', new InMemoryKeyStore());

        $info = $this->controller($keys)->get()->data()['token_encryption'];

        $this->assertSame('own', $info['source']);
        $this->assertStringContainsString('at least 32 characters', $info['problem']);
    }

    public function test_the_settings_payload_carries_no_key_material(): void
    {
        $configured = 'a-secret-wp-config-key-0123456789-abcdef';
        $own = InMemoryKeyStore::randomKey();
        $payloads = array(
            $this->controller(KeyRing::fromMaterial($configured, 'salts-material', new InMemoryKeyStore($own)))->get(),
            $this->controller(KeyRing::fromMaterial('short-secret', 'salts-material', new InMemoryKeyStore($own)))->get(),
            $this->controller(KeyRing::fromMaterial(null, 'salts-material', new InMemoryKeyStore($own)))->get(),
        );

        foreach ($payloads as $response) {
            $json = json_encode($response->data());
            $this->assertStringNotContainsString($configured, $json);
            $this->assertStringNotContainsString('short-secret', $json);
            $this->assertStringNotContainsString('salts-material', $json);
            $this->assertStringNotContainsString(base64_encode($own), $json);
            $this->assertStringNotContainsString(bin2hex($own), $json);
        }
    }

    public function test_save_responds_with_the_token_encryption_status_too(): void
    {
        $response = $this->controller()->save(array('alert_recipients' => 'ops@nara.ae'));

        $this->assertSame('own', $response->data()['token_encryption']['source']);
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

    public function test_save_without_the_toggle_keys_keeps_the_stored_values(): void
    {
        (new SettingsRepository())->save(new Settings('ops@nara.ae', false, true));

        $response = $this->controller()->save(array('alert_recipients' => 'lead@nara.ae'));

        $this->assertSame(200, $response->status());
        $stored = (new SettingsRepository())->get();
        $this->assertSame('lead@nara.ae', $stored->alertRecipients());
        $this->assertFalse($stored->shouldEmailOnFailure());
        $this->assertTrue($stored->shouldEmailOnSuccess());
    }

    public function test_send_test_uses_the_submitted_list_without_saving_it(): void
    {
        (new SettingsRepository())->save(new Settings('ops@nara.ae', true, true));
        $sent = array();
        Functions\when('wp_mail')->alias(function ($to, $subject, $body) use (&$sent) {
            $sent[] = array('to' => $to, 'subject' => $subject, 'body' => $body);
            return true;
        });

        $response = $this->controller()->sendTest(array('alert_recipients' => 'draft@nara.ae, Draft@nara.ae'));

        $this->assertSame(200, $response->status());
        $this->assertSame(array('draft@nara.ae'), $response->data()['sent_to']);
        $this->assertSame(array('draft@nara.ae'), $sent[0]['to']);
        $this->assertSame('ops@nara.ae', (new SettingsRepository())->get()->alertRecipients());
    }

    public function test_send_test_rejects_a_bad_submitted_address_with_422(): void
    {
        Functions\when('wp_mail')->justReturn(true);

        $response = $this->controller()->sendTest(array('alert_recipients' => 'ops@nara.ae, oops-at-nara'));

        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('oops-at-nara', $response->data()['error']);
    }

    public function test_send_test_falls_back_to_the_admin_email_for_an_empty_submitted_list(): void
    {
        Functions\when('wp_mail')->justReturn(true);

        $response = $this->controller()->sendTest(array('alert_recipients' => ''));

        $this->assertSame(array('admin@example.com'), $response->data()['sent_to']);
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
