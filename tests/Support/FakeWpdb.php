<?php

namespace Deployward\Tests\Support;

/** Models the MySQL behaviour of the one options INSERT the plugin runs. */
final class FakeWpdb
{
    /** @var string */
    public $prefix = 'wp_';
    /** @var string */
    public $options = 'wp_options';
    /** @var string */
    public $last_error = '';
    /** @var array */
    public $rows;
    /** @var array */
    public $autoloads = array();
    /** @var ?callable */
    public $beforeInsert = null;
    /** @var bool */
    public $isFailing = false;
    /** @var int */
    public $queries = 0;

    public function __construct(array &$rows)
    {
        $this->rows = &$rows;
    }

    public function prepare(string $query, ...$args): string
    {
        foreach ($args as $arg) {
            $query = preg_replace('/%s/', "'" . addslashes((string) $arg) . "'", $query, 1);
        }

        return $query;
    }

    public function query(string $sql)
    {
        $this->queries++;
        if ($this->isFailing) {
            $this->last_error = 'simulated database error';
            return false;
        }
        if (! preg_match("/VALUES \('([^']*)', '([^']*)', '([^']*)'\)/", $sql, $m)) {
            return false;
        }
        if ($this->beforeInsert !== null) {
            call_user_func($this->beforeInsert);
        }
        $exists = array_key_exists($m[1], $this->rows);
        if ($exists && strpos($sql, 'INSERT IGNORE') !== false) {
            return 0;
        }
        if ($exists && strpos($sql, 'ON DUPLICATE KEY UPDATE') === false) {
            $this->last_error = 'Duplicate entry';
            return false;
        }
        $this->rows[$m[1]] = $m[2];
        $this->autoloads[$m[1]] = $m[3];

        return 1;
    }
}
