<?php

namespace Deployward\Tests\Unit\Deploy;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\Deploy\BackupManager;
use Deployward\Deploy\DirectoryMover;
use Deployward\Deploy\DirectoryMoverInterface;
use Deployward\Support\Result;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class BackupManagerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

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
        $manager = new BackupManager($this->tmp . '/backups', new DirectoryMover());

        $result = $manager->backup($target, 'nara-core', 'aaaaaaaa');

        $this->assertTrue($result->isOk());
        $this->assertDirectoryDoesNotExist($target);
        $this->assertFileExists($result->data() . '/version.txt');
    }

    public function test_backup_skips_when_target_missing(): void
    {
        $manager = new BackupManager($this->tmp . '/backups', new DirectoryMover());

        $result = $manager->backup($this->tmp . '/plugins/none', 'none', 'aaaaaaaa');

        $this->assertTrue($result->isSkipped());
    }

    public function test_restore_latest_brings_back_previous_version(): void
    {
        $target = $this->makeTarget('v1');
        $manager = new BackupManager($this->tmp . '/backups', new DirectoryMover());
        $manager->backup($target, 'nara-core', 'aaaaaaaa');
        $this->makeTarget('v2');

        $restore = $manager->restoreLatest('nara-core', $target);

        $this->assertTrue($restore->isOk());
        $this->assertSame('v1', file_get_contents($target . '/version.txt'));
    }

    public function test_prune_keeps_only_n_newest(): void
    {
        $manager = new BackupManager($this->tmp . '/backups', new DirectoryMover());
        foreach (array('20260101-000001-a', '20260101-000002-b', '20260101-000003-c', '20260101-000004-d') as $name) {
            mkdir($this->tmp . '/backups/nara-core/' . $name, 0777, true);
        }

        $manager->prune('nara-core', 2);

        $remaining = array_values(array_diff(scandir($this->tmp . '/backups/nara-core'), array('.', '..')));
        $this->assertCount(2, $remaining);
        $this->assertContains('20260101-000004-d', $remaining);
        $this->assertContains('20260101-000003-c', $remaining);
    }

    public function test_ensure_protected_writes_guard_files(): void
    {
        $manager = new BackupManager($this->tmp . '/backups', new DirectoryMover());
        $manager->ensureProtected();

        $this->assertFileExists($this->tmp . '/backups/index.php');
        $this->assertFileExists($this->tmp . '/backups/.htaccess');
    }

    public function test_latest_returns_null_when_no_backups(): void
    {
        $manager = new BackupManager($this->tmp . '/backups', new DirectoryMover());

        $this->assertNull($manager->latest('nara-core'));
    }

    public function test_restore_latest_fails_when_no_backup(): void
    {
        $manager = new BackupManager($this->tmp . '/backups', new DirectoryMover());

        $result = $manager->restoreLatest('nara-core', $this->tmp . '/plugins/nara-core');

        $this->assertFalse($result->isOk());
    }

    public function test_prune_deletes_the_old_backups(): void
    {
        $manager = new BackupManager($this->tmp . '/backups', new DirectoryMover());
        foreach (array('20260101-000001-a', '20260101-000002-b', '20260101-000003-c') as $name) {
            mkdir($this->tmp . '/backups/nara-core/' . $name, 0777, true);
        }

        $manager->prune('nara-core', 1);

        $this->assertDirectoryDoesNotExist($this->tmp . '/backups/nara-core/20260101-000001-a');
        $this->assertDirectoryDoesNotExist($this->tmp . '/backups/nara-core/20260101-000002-b');
        $this->assertDirectoryExists($this->tmp . '/backups/nara-core/20260101-000003-c');
    }

    public function test_backup_fails_clearly_when_backup_dir_cannot_be_created(): void
    {
        $target = $this->makeTarget('v1');
        Functions\when('wp_mkdir_p')->justReturn(false);
        $manager = new BackupManager($this->tmp . '/backups', new DirectoryMover());

        $result = $manager->backup($target, 'nara-core', 'aaaaaaaa');

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString($this->tmp . '/backups/nara-core', $result->message());
        $this->assertStringContainsString('writable', $result->message());
    }

    public function test_backup_reports_mover_reason_on_move_failure(): void
    {
        $target = $this->makeTarget('v1');
        $mover = Mockery::mock(DirectoryMoverInterface::class);
        $mover->shouldReceive('move')->once()->andReturn(Result::fail('rename(): Permission denied'));
        $manager = new BackupManager($this->tmp . '/backups', $mover);

        $result = $manager->backup($target, 'nara-core', 'aaaaaaaa');

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('Permission denied', $result->message());
    }

    public function test_restore_reports_mover_reason(): void
    {
        mkdir($this->tmp . '/backups/nara-core/20260101-000001-a', 0777, true);
        $target = $this->makeTarget('v-current');
        $mover = Mockery::mock(DirectoryMoverInterface::class);
        $mover->shouldReceive('move')->once()->andReturn(Result::fail('EXDEV: cross-device link'));
        $manager = new BackupManager($this->tmp . '/backups', $mover);

        $result = $manager->restoreLatest('nara-core', $target);

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('cross-device link', $result->message());
    }
}
