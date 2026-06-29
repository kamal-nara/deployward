<?php

namespace Deployward\Tests\Unit\Deploy;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\Deploy\BackupManager;
use PHPUnit\Framework\TestCase;

final class BackupManagerTest extends TestCase
{
    private $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('wp_mkdir_p')->alias(function ($dir) {
            return is_dir($dir) || mkdir($dir, 0777, true);
        });
        $this->tmp = sys_get_temp_dir() . '/dw-backup-' . uniqid();
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        @exec('rm -rf ' . escapeshellarg($this->tmp));
        Monkey\tearDown();
        parent::tearDown();
    }

    private function makeTarget(string $marker): string
    {
        $target = $this->tmp . '/plugins/nara-core';
        if (! is_dir($target)) {
            mkdir($target, 0777, true);
        }
        file_put_contents($target . '/version.txt', $marker);

        return $target;
    }

    public function test_backup_moves_current_target_aside(): void
    {
        $target = $this->makeTarget('v1');
        $manager = new BackupManager($this->tmp . '/backups');

        $result = $manager->backup($target, 'nara-core', 'aaaaaaaa');

        $this->assertTrue($result->isOk());
        $this->assertDirectoryDoesNotExist($target);
        $this->assertFileExists($result->data() . '/version.txt');
    }

    public function test_backup_skips_when_target_missing(): void
    {
        $manager = new BackupManager($this->tmp . '/backups');

        $result = $manager->backup($this->tmp . '/plugins/none', 'none', 'aaaaaaaa');

        $this->assertTrue($result->isSkipped());
    }

    public function test_restore_latest_brings_back_previous_version(): void
    {
        $target = $this->makeTarget('v1');
        $manager = new BackupManager($this->tmp . '/backups');
        $manager->backup($target, 'nara-core', 'aaaaaaaa');
        $this->makeTarget('v2');

        $restore = $manager->restoreLatest('nara-core', $target);

        $this->assertTrue($restore->isOk());
        $this->assertSame('v1', file_get_contents($target . '/version.txt'));
    }

    public function test_prune_keeps_only_n_newest(): void
    {
        $manager = new BackupManager($this->tmp . '/backups');
        foreach (array('20260101-000001-a', '20260101-000002-b', '20260101-000003-c', '20260101-000004-d') as $name) {
            mkdir($this->tmp . '/backups/nara-core/' . $name, 0777, true);
        }

        $manager->prune('nara-core', 2);

        $remaining = array_values(array_diff(scandir($this->tmp . '/backups/nara-core'), array('.', '..')));
        $this->assertCount(2, $remaining);
        $this->assertContains('20260101-000004-d', $remaining);
        $this->assertContains('20260101-000003-c', $remaining);
    }
}
