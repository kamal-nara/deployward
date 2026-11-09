<?php

namespace Deployward\Tests\Unit\Security;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\Security\OwnKeyStore;
use PHPUnit\Framework\TestCase;

final class OwnKeyStoreTest extends TestCase
{
    /** @var array */
    private $options = array();
    /** @var array */
    private $autoloads = array();
    /** @var int */
    private $adds = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->options = array();
        $this->autoloads = array();
        $this->adds = 0;

        Functions\when('get_option')->alias(function ($key, $default = false) {
            return array_key_exists($key, $this->options) ? $this->options[$key] : $default;
        });
        Functions\when('add_option')->alias(function ($key, $value = '', $deprecated = '', $autoload = 'yes') {
            $this->adds++;
            if (array_key_exists($key, $this->options)) {
                return false;
            }
            $this->options[$key] = $value;
            $this->autoloads[$key] = $autoload;
            return true;
        });
        Functions\when('update_option')->alias(function ($key, $value, $autoload = null) {
            if (array_key_exists($key, $this->options) && $this->options[$key] === $value) {
                return false;
            }
            $this->options[$key] = $value;
            $this->autoloads[$key] = $autoload;
            return true;
        });
        Functions\when('delete_option')->alias(function ($key) {
            unset($this->options[$key]);
            return true;
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_there_is_no_key_until_one_is_needed(): void
    {
        $store = new OwnKeyStore();

        $this->assertNull($store->current());
        $this->assertNull($store->previous());
        $this->assertSame(array(), $this->options);
    }

    public function test_ensure_creates_32_random_bytes_stored_as_base64_without_autoload(): void
    {
        $key = (new OwnKeyStore())->ensure();

        $this->assertSame(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, strlen($key));
        $this->assertSame(base64_encode($key), $this->options[OwnKeyStore::OPTION]);
        $this->assertFalse($this->autoloads[OwnKeyStore::OPTION]);
    }

    public function test_ensure_returns_the_same_key_every_time_and_creates_it_once(): void
    {
        $store = new OwnKeyStore();

        $first = $store->ensure();
        $second = (new OwnKeyStore())->ensure();

        $this->assertSame($first, $second);
        $this->assertSame(1, $this->adds);
    }

    public function test_ensure_never_replaces_a_stored_key(): void
    {
        $existing = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $this->options[OwnKeyStore::OPTION] = base64_encode($existing);

        $this->assertSame($existing, (new OwnKeyStore())->ensure());
        $this->assertSame(0, $this->adds);
    }

    public function test_ensure_refuses_to_overwrite_a_damaged_stored_key(): void
    {
        $this->options[OwnKeyStore::OPTION] = 'not-a-valid-key';

        try {
            (new OwnKeyStore())->ensure();
            $this->fail('A damaged key must not be replaced silently.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('damaged', $e->getMessage());
        }

        $this->assertSame('not-a-valid-key', $this->options[OwnKeyStore::OPTION]);
    }

    public function test_ensure_uses_the_key_another_request_stored_first(): void
    {
        $winner = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        Functions\when('add_option')->alias(function ($key) use ($winner) {
            $this->options[$key] = base64_encode($winner);
            return false;
        });

        $this->assertSame($winner, (new OwnKeyStore())->ensure());
    }

    public function test_rotate_keeps_the_old_key_as_previous_and_stores_the_new_one(): void
    {
        $store = new OwnKeyStore();
        $old = $store->ensure();
        $new = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

        $store->rotate($new);

        $this->assertSame($new, $store->current());
        $this->assertSame($old, $store->previous());
        $this->assertFalse($this->autoloads[OwnKeyStore::PREVIOUS_OPTION]);
    }

    public function test_finish_rotation_forgets_the_previous_key(): void
    {
        $store = new OwnKeyStore();
        $store->ensure();
        $store->rotate(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));

        $store->finishRotation();

        $this->assertNull($store->previous());
    }

    public function test_rotate_refuses_a_key_of_the_wrong_size(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new OwnKeyStore())->rotate('short');
    }
}
