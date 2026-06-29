<?php

namespace Deployward\Log;

final class DeployLog
{
    /** @var object */
    private $wpdb;

    public function __construct($wpdb)
    {
        $this->wpdb = $wpdb;
    }

    public function table(): string
    {
        return $this->wpdb->prefix . 'deployward_log';
    }

    public function installTable(): void
    {
        $table = $this->table();
        $charset = method_exists($this->wpdb, 'get_charset_collate') ? $this->wpdb->get_charset_collate() : '';
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            deployment_id VARCHAR(64) NOT NULL,
            sha VARCHAR(64) NOT NULL DEFAULT '',
            trigger_source VARCHAR(32) NOT NULL DEFAULT '',
            status VARCHAR(16) NOT NULL DEFAULT '',
            message TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY deployment_id (deployment_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public function record(array $row): void
    {
        $this->wpdb->insert(
            $this->table(),
            array(
                'deployment_id' => isset($row['deployment_id']) ? (string) $row['deployment_id'] : '',
                'sha' => isset($row['sha']) ? (string) $row['sha'] : '',
                'trigger_source' => isset($row['trigger']) ? (string) $row['trigger'] : '',
                'status' => isset($row['status']) ? (string) $row['status'] : '',
                'message' => isset($row['message']) ? (string) $row['message'] : '',
                'created_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s')
        );
    }

    public function recent(string $deploymentId, int $limit = 20, int $offset = 0): array
    {
        $table = $this->table();
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$table} WHERE deployment_id = %s ORDER BY id DESC LIMIT %d OFFSET %d",
            $deploymentId,
            $limit,
            $offset
        );
        $rows = $this->wpdb->get_results($sql, ARRAY_A);

        return is_array($rows) ? $rows : array();
    }
}
