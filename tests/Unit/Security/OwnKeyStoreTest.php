<?php

namespace Deployward\Tests\Unit\Security;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\Security\OwnKeyStore;
use Deployward\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class OwnKeyStoreTest extends TestCase
{
    /** @var array */
    private $options = array();
    /** @var array */
    private $autoloads = array();
    /** @var FakeWpdb */
    private $db;
    /** @var int */
    private $emptyReads = 0;
    /** @var array */
    private $cacheDeletes = array();

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->options = array();
        $this->autoloads = array();
        $this->emptyReads = 0;
        $this->cacheDeletes = array();
        $this->db = new FakeWpdb($this->options);

        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($this->emptyReads > 0) {
                $this->emptyReads--;
                return $default;
            }
            return array_key_exists($key, $this->options) ? $this->options[$key] : $default;
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
        Functions\when('wp_cache_delete')->alias(function ($key, $group = '') {
            $this->cacheDeletes[] = array($key, $group);
            return true;
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function store(): OwnKeyStore
    {
        return new OwnKeyStore($this->db);
    }

    private static function randomKey(): string
    {
        return random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function test_there_is_no_key_until_one_is_needed(): void
    {
        $store = $this->store();

        $this->assertNull($store->current());
        $this->assertNull($store->previous());
        $this->assertSame(array(), $this->options);
    }

    public function test_ensure_creates_32_random_bytes_stored_as_base64_without_autoload(): void
    {
        $key = $this->store()->ensure();

        $this->assertSame(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, strlen($key));
        $this->assertSame(base64_encode($key), $this->options[OwnKeyStore::OPTION]);
        $this->assertSame('no', $this->db->autoloads[OwnKeyStore::OPTION]);
    }

    public function test_ensure_returns_the_same_key_every_time(): void
    {
        $first = $this->store()->ensure();
        $second = $this->store()->ensure();

        $this->assertSame($first, $second);
    }

    public function test_ensure_never_replaces_a_stored_key(): void
    {
        $existing = self::randomKey();
        $this->options[OwnKeyStore::OPTION] = base64_encode($existing);

        $this->assertSame($existing, $this->store()->ensure());
        $this->assertSame(base64_encode($existing), $this->options[OwnKeyStore::OPTION]);
    }

    public function test_ensure_refuses_to_overwrite_a_damaged_stored_key(): void
    {
        $this->options[OwnKeyStore::OPTION] = 'not-a-valid-key';

        try {
            $this->store()->ensure();
            $this->fail('A damaged key must not be replaced silently.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('damaged', $e->getMessage());
        }

        $this->assertSame('not-a-valid-key', $this->options[OwnKeyStore::OPTION]);
    }

    public function test_a_key_another_request_stores_first_is_never_overwritten(): void
    {
        $winner = self::randomKey();
        $this->db->beforeInsert = function () use ($winner) {
            $this->options[OwnKeyStore::OPTION] = base64_encode($winner);
        };

        $this->assertSame($winner, $this->store()->ensure());
        $this->assertSame(base64_encode($winner), $this->options[OwnKeyStore::OPTION]);
    }

    public function test_an_empty_read_of_an_existing_key_does_not_replace_it(): void
    {
        $existing = self::randomKey();
        $this->options[OwnKeyStore::OPTION] = base64_encode($existing);
        $this->emptyReads = 2;

        $this->assertSame($existing, $this->store()->ensure());
        $this->assertSame(base64_encode($existing), $this->options[OwnKeyStore::OPTION]);
    }

    public function test_the_option_caches_are_cleared_so_the_stored_key_is_read_back(): void
    {
        $this->store()->ensure();

        $this->assertContains(array(OwnKeyStore::OPTION, 'options'), $this->cacheDeletes);
        $this->assertContains(array('notoptions', 'options'), $this->cacheDeletes);
    }

    public function test_a_database_error_is_a_failure_not_an_absent_key(): void
    {
        $this->db->isFailing = true;

        try {
            $this->store()->ensure();
            $this->fail('A failed write must not be treated as success.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('could not store', $e->getMessage());
        }

        $this->assertSame(array(), $this->options);
    }

    public function test_rotate_keeps_the_old_key_as_previous_and_stores_the_new_one(): void
    {
        $store = $this->store();
        $old = $store->ensure();
        $new = self::randomKey();

        $store->rotate($new);

        $this->assertSame($new, $store->current());
        $this->assertSame($old, $store->previous());
        $this->assertFalse($this->autoloads[OwnKeyStore::PREVIOUS_OPTION]);
    }

    public function test_finish_rotation_forgets_the_previous_key(): void
    {
        $store = $this->store();
        $store->ensure();
        $store->rotate(self::randomKey());

        $store->finishRotation();

        $this->assertNull($store->previous());
    }

    public function test_rotate_refuses_a_key_of_the_wrong_size(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->store()->rotate('short');
    }
}
