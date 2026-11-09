<?php

namespace Deployward\Tests\Unit\Deploy;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\Deploy\StorageNames;
use Deployward\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class StorageNamesTest extends TestCase
{
    /** @var array */
    private $options = array();
    /** @var FakeWpdb */
    private $db;
    /** @var string */
    private $previousErrorLog = '';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->options = array();
        $this->db = new FakeWpdb($this->options);
        $this->previousErrorLog = (string) ini_get('error_log');
        ini_set('error_log', '/dev/null');

        Functions\when('get_option')->alias(function ($key, $default = false) {
            return array_key_exists($key, $this->options) ? $this->options[$key] : $default;
        });
        Functions\when('wp_cache_delete')->justReturn(true);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_the_suffix_is_random_hex_stored_once_without_autoload(): void
    {
        $suffix = (new StorageNames($this->db))->suffix();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $suffix);
        $this->assertSame($suffix, $this->options[StorageNames::OPTION]);
        $this->assertSame('no', $this->db->autoloads[StorageNames::OPTION]);
    }

    public function test_the_suffix_is_reused_by_every_later_call_and_request(): void
    {
        $first = (new StorageNames($this->db))->suffix();
        $second = (new StorageNames($this->db))->suffix();
        $third = (new StorageNames($this->db))->suffix();

        $this->assertSame($first, $second);
        $this->assertSame($first, $third);
    }

    public function test_two_sites_do_not_share_a_suffix(): void
    {
        $first = (new StorageNames($this->db))->suffix();
        $this->options = array();
        $second = (new StorageNames($this->db))->suffix();

        $this->assertNotSame($first, $second);
    }

    public function test_a_stored_suffix_is_never_replaced(): void
    {
        $this->options[StorageNames::OPTION] = 'abcdef0123456789';

        $this->assertSame('abcdef0123456789', (new StorageNames($this->db))->suffix());
        $this->assertSame('abcdef0123456789', $this->options[StorageNames::OPTION]);
    }

    public function test_a_suffix_stored_by_a_racing_request_wins(): void
    {
        $this->db->beforeInsert = function () {
            $this->options[StorageNames::OPTION] = '0011223344556677';
        };

        $this->assertSame('0011223344556677', (new StorageNames($this->db))->suffix());
    }

    public function test_a_malformed_stored_value_is_left_alone_and_refused(): void
    {
        $this->options[StorageNames::OPTION] = '../escape';

        try {
            (new StorageNames($this->db))->suffix();
            $this->fail('A malformed suffix must not be used.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not valid', $e->getMessage());
        }

        $this->assertSame('../escape', $this->options[StorageNames::OPTION]);
    }

    public function test_a_database_failure_is_an_error_not_a_guessable_fallback_name(): void
    {
        $this->db->isFailing = true;

        try {
            (new StorageNames($this->db))->suffix();
            $this->fail('A failed write must not fall back to a fixed name.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('could not store', $e->getMessage());
        }

        $this->assertSame(array(), $this->options);
    }

    public function test_there_is_no_fixed_fallback_suffix(): void
    {
        $this->assertFalse(defined(StorageNames::class . '::FALLBACK'));
    }

    public function test_the_suffix_is_looked_up_once_per_instance(): void
    {
        $names = new StorageNames($this->db);
        $first = $names->suffix();
        $queries = $this->db->queries;
        $this->options = array();

        $this->assertSame($first, $names->suffix());
        $this->assertSame($queries, $this->db->queries);
    }
}
