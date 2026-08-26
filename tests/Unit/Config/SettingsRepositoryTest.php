<?php

namespace Deployward\Tests\Unit\Config;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\Config\Settings;
use Deployward\Config\SettingsRepository;
use PHPUnit\Framework\TestCase;

final class SettingsRepositoryTest extends TestCase
{
    private $store = array();
    private $lastAutoload = null;

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
            $this->lastAutoload = $autoload;
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

    private function repository(): SettingsRepository
    {
        return new SettingsRepository();
    }

    public function test_defaults_email_on_failure_and_success(): void
    {
        $settings = $this->repository()->get();

        $this->assertSame('', $settings->alertRecipients());
        $this->assertTrue($settings->shouldEmailOnFailure());
        $this->assertTrue($settings->shouldEmailOnSuccess());
    }

    public function test_recipients_fall_back_to_the_admin_email_when_empty(): void
    {
        $this->assertSame(array('admin@example.com'), $this->repository()->recipients());
    }

    public function test_recipients_split_trim_and_keep_valid_addresses(): void
    {
        $repo = $this->repository();
        $repo->save(new Settings(' ops@nara.ae , kamal@nara.ae ', true, true));

        $this->assertSame(array('ops@nara.ae', 'kamal@nara.ae'), $repo->recipients());
    }

    public function test_recipients_drop_invalid_entries(): void
    {
        $repo = $this->repository();
        $repo->save(new Settings('ops@nara.ae,not-an-email', true, true));

        $this->assertSame(array('ops@nara.ae'), $repo->recipients());
    }

    public function test_recipients_are_unique(): void
    {
        $repo = $this->repository();
        $repo->save(new Settings('ops@nara.ae, ops@nara.ae', true, true));

        $this->assertSame(array('ops@nara.ae'), $repo->recipients());
    }

    public function test_recipients_fall_back_when_every_entry_is_invalid(): void
    {
        $repo = $this->repository();
        $repo->save(new Settings('nope, also-nope', true, true));

        $this->assertSame(array('admin@example.com'), $repo->recipients());
    }

    public function test_save_round_trips_the_toggles_with_autoload_off(): void
    {
        $repo = $this->repository();
        $repo->save(new Settings('ops@nara.ae', false, false));

        $settings = $repo->get();
        $this->assertSame('ops@nara.ae', $settings->alertRecipients());
        $this->assertFalse($settings->shouldEmailOnFailure());
        $this->assertFalse($settings->shouldEmailOnSuccess());
        $this->assertFalse($this->lastAutoload);
    }

    public function test_get_survives_a_corrupt_option(): void
    {
        $this->store[SettingsRepository::OPTION] = 'not-an-array';

        $this->assertTrue($this->repository()->get()->shouldEmailOnFailure());
    }

    public function test_split_recipients_dedupes_case_insensitively_keeping_the_first_spelling(): void
    {
        $this->assertSame(array('A@x.com'), Settings::splitRecipients('A@x.com, a@x.com'));
        $this->assertSame(array('a@x.com', 'B@x.com'), Settings::splitRecipients('a@x.com, B@x.com, A@X.COM'));
    }

    public function test_split_recipients_drops_empty_segments(): void
    {
        $this->assertSame(array('a@x.com', 'b@x.com'), Settings::splitRecipients('a@x.com,, b@x.com ,'));
    }
}
