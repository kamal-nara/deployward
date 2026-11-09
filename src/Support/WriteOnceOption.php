<?php

namespace Deployward\Support;

/**
 * An option that is written once and never overwritten by anything automatic.
 * add_option() would overwrite on a race or after an empty read, because it skips its
 * existence check when the option is cached as absent. INSERT IGNORE cannot overwrite.
 */
final class WriteOnceOption
{
    /** @var object */
    private $wpdb;
    /** @var string */
    private $name;

    public function __construct($wpdb, string $name)
    {
        $this->wpdb = $wpdb;
        $this->name = $name;
    }

    public function read(): string
    {
        $value = get_option($this->name, '');

        return is_string($value) ? $value : '';
    }

    public function insertIfAbsent(string $value): string
    {
        $sql = $this->wpdb->prepare(
            "INSERT IGNORE INTO {$this->wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
            $this->name,
            $value,
            'no'
        );
        $result = $this->wpdb->query($sql);
        wp_cache_delete($this->name, 'options');
        wp_cache_delete('notoptions', 'options');
        if ($result === false || $this->wpdb->last_error !== '') {
            throw new \RuntimeException('Deployward could not store the option ' . $this->name . '.');
        }

        return $this->read();
    }
}
