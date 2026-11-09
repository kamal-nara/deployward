<?php

namespace Deployward\Tests\Unit\Support;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\Support\WriteOnceOption;
use Deployward\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class WriteOnceOptionTest extends TestCase
{
    const NAME = 'deployward_example';

    /** @var array */
    private $options = array();
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

    private function option(): WriteOnceOption
    {
        return new WriteOnceOption($this->db, self::NAME);
    }

    public function test_it_reads_an_empty_string_while_nothing_is_stored(): void
    {
        $this->assertSame('', $this->option()->read());
    }

    public function test_it_stores_the_value_without_autoload_and_returns_it(): void
    {
        $stored = $this->option()->insertIfAbsent('first');

        $this->assertSame('first', $stored);
        $this->assertSame('first', $this->options[self::NAME]);
        $this->assertSame('no', $this->db->autoloads[self::NAME]);
    }

    public function test_it_never_overwrites_a_value_stored_by_another_request(): void
    {
        $this->db->beforeInsert = function () {
            $this->options[self::NAME] = 'winner';
        };

        $this->assertSame('winner', $this->option()->insertIfAbsent('late'));
        $this->assertSame('winner', $this->options[self::NAME]);
    }

    public function test_it_never_overwrites_after_an_empty_read_of_an_existing_value(): void
    {
        $this->options[self::NAME] = 'existing';
        $this->emptyReads = 1;

        $this->assertSame('', $this->option()->read());
        $this->assertSame('existing', $this->option()->insertIfAbsent('replacement'));
        $this->assertSame('existing', $this->options[self::NAME]);
    }

    public function test_it_clears_the_option_caches_so_the_stored_value_is_read_back(): void
    {
        $this->option()->insertIfAbsent('first');

        $this->assertContains(array(self::NAME, 'options'), $this->cacheDeletes);
        $this->assertContains(array('notoptions', 'options'), $this->cacheDeletes);
    }

    public function test_a_database_error_is_a_failure(): void
    {
        $this->db->isFailing = true;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('could not store');

        $this->option()->insertIfAbsent('first');
    }
}
