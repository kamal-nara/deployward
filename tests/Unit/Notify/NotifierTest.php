<?php

namespace Deployward\Tests\Unit\Notify;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\Config\Settings;
use Deployward\Config\SettingsRepository;
use Deployward\Notify\Notifier;
use PHPUnit\Framework\TestCase;

final class NotifierTest extends TestCase
{
    private $store = array();
    private $sent = array();

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->store = array('admin_email' => 'admin@example.com');
        $this->sent = array();

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
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function notifier(): Notifier
    {
        return new Notifier(new SettingsRepository());
    }

    private function store(Settings $settings): void
    {
        (new SettingsRepository())->save($settings);
    }

    public function test_a_failure_goes_to_the_configured_recipients(): void
    {
        $this->store(new Settings('ops@nara.ae', true, true));

        $this->notifier()->notify('[Deployward] FAILED', 'boom', 'failure');

        $this->assertCount(1, $this->sent);
        $this->assertSame(array('ops@nara.ae'), $this->sent[0]['to']);
    }

    public function test_a_success_is_suppressed_when_the_success_toggle_is_off(): void
    {
        $this->store(new Settings('ops@nara.ae', true, false));

        $this->notifier()->notify('[Deployward] SUCCESS', 'deployed', 'success');

        $this->assertCount(0, $this->sent);
    }

    public function test_a_success_goes_out_when_the_success_toggle_is_on(): void
    {
        $this->store(new Settings('ops@nara.ae', true, true));

        $this->notifier()->notify('[Deployward] SUCCESS', 'deployed', 'success');

        $this->assertCount(1, $this->sent);
    }

    public function test_a_failure_is_suppressed_when_the_failure_toggle_is_off(): void
    {
        $this->store(new Settings('ops@nara.ae', false, true));

        $this->notifier()->notify('[Deployward] FAILED', 'boom', 'failure');

        $this->assertCount(0, $this->sent);
    }

    public function test_an_alert_goes_out_even_with_deploy_failure_email_off(): void
    {
        $this->store(new Settings('ops@nara.ae', false, false));

        $this->notifier()->notify('[Deployward] Token expires', 'renew it', 'alert');

        $this->assertCount(1, $this->sent);
        $this->assertSame(array('ops@nara.ae'), $this->sent[0]['to']);
    }

    public function test_an_alert_goes_out_with_every_toggle_on(): void
    {
        $this->store(new Settings('ops@nara.ae', true, true));

        $this->notifier()->notify('[Deployward] Token expires', 'renew it', 'alert');

        $this->assertCount(1, $this->sent);
    }

    public function test_the_default_kind_is_a_failure(): void
    {
        $this->store(new Settings('ops@nara.ae', false, true));

        $this->notifier()->notify('[Deployward] FAILED', 'boom');

        $this->assertCount(0, $this->sent);
    }

    public function test_nothing_is_mailed_when_no_recipient_can_be_resolved(): void
    {
        $this->store = array('admin_email' => '');
        $this->store(new Settings('', true, true));

        $this->notifier()->notify('[Deployward] FAILED', 'boom');

        $this->assertCount(0, $this->sent);
    }
}
