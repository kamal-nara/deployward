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

    private function makeBackup(string $root, string $slug, string $name, string $marker = 'x'): string
    {
        $dir = $this->tmp . '/uploads/' . $root . '/' . $slug . '/' . $name;
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/version.txt', $marker);

        return $dir;
    }

    private function manager(): BackupManager
    {
        return new BackupManager($this->tmp . '/uploads/deployward-backups-newsuffix', new DirectoryMover());
    }

    private function names(string $root, string $slug): array
    {
        $dir = $this->tmp . '/uploads/' . $root . '/' . $slug;

        return is_dir($dir) ? array_values(array_diff(scandir($dir), array('.', '..'))) : array();
    }

    public function test_restore_finds_a_backup_made_under_an_older_suffix(): void
    {
        $this->makeBackup('deployward-backups-oldsuffix', 'nara-core', '20260101-000001-aaaaaaaa', 'from-before-the-salt-change');
        $target = $this->makeTarget('broken-current');

        $result = $this->manager()->restoreLatest('nara-core', $target);

        $this->assertTrue($result->isOk(), $result->message());
        $this->assertSame('from-before-the-salt-change', file_get_contents($target . '/version.txt'));
    }

    public function test_latest_is_the_newest_backup_across_every_suffix_directory(): void
    {
        $this->makeBackup('deployward-backups-oldsuffix', 'nara-core', '20260301-000001-bbbbbbbb', 'newest-in-legacy');
        $this->makeBackup('deployward-backups-newsuffix', 'nara-core', '20260201-000001-aaaaaaaa', 'older-in-current');
        $target = $this->makeTarget('broken-current');

        $this->manager()->restoreLatest('nara-core', $target);

        $this->assertSame('newest-in-legacy', file_get_contents($target . '/version.txt'));
    }

    public function test_new_backups_go_into_the_current_directory_only(): void
    {
        $this->makeBackup('deployward-backups-oldsuffix', 'nara-core', '20260101-000001-aaaaaaaa');
        $target = $this->makeTarget('v1');

        $result = $this->manager()->backup($target, 'nara-core', 'cccccccc');

        $this->assertTrue($result->isOk());
        $this->assertStringStartsWith($this->tmp . '/uploads/deployward-backups-newsuffix/nara-core/', $result->data());
        $this->assertCount(1, $this->names('deployward-backups-oldsuffix', 'nara-core'));
    }

    public function test_prune_keeps_the_newest_n_across_every_suffix_directory(): void
    {
        $this->makeBackup('deployward-backups-oldest', 'nara-core', '20260101-000001-aaaaaaaa');
        $this->makeBackup('deployward-backups-oldest', 'nara-core', '20260102-000001-bbbbbbbb');
        $this->makeBackup('deployward-backups-older', 'nara-core', '20260103-000001-cccccccc');
        $this->makeBackup('deployward-backups-newsuffix', 'nara-core', '20260104-000001-dddddddd');
        $this->makeBackup('deployward-backups-newsuffix', 'nara-core', '20260105-000001-eeeeeeee');

        $this->manager()->prune('nara-core', 3);

        $this->assertSame(array(), $this->names('deployward-backups-oldest', 'nara-core'));
        $this->assertSame(array('20260103-000001-cccccccc'), $this->names('deployward-backups-older', 'nara-core'));
        $this->assertSame(
            array('20260104-000001-dddddddd', '20260105-000001-eeeeeeee'),
            $this->names('deployward-backups-newsuffix', 'nara-core')
        );
    }

    public function test_prune_deletes_nothing_that_is_not_a_backup_of_that_deployment(): void
    {
        $this->makeBackup('deployward-backups-oldsuffix', 'nara-core', '20260101-000001-aaaaaaaa');
        $this->makeBackup('deployward-backups-oldsuffix', 'other-plugin', '20260101-000001-aaaaaaaa');
        $this->makeBackup('deployward-backups-oldsuffix', 'nara-core', 'manual-copy');
        file_put_contents($this->tmp . '/uploads/deployward-backups-oldsuffix/nara-core/notes.txt', 'keep me');
        mkdir($this->tmp . '/uploads/deployward-work-oldsuffix/dw-extract-1', 0777, true);
        mkdir($this->tmp . '/uploads/deployward-backups-oldsuffix.bak/nara-core/20260101-000001-aaaaaaaa', 0777, true);
        mkdir($this->tmp . '/uploads/some-other-plugin/nara-core/20260101-000001-aaaaaaaa', 0777, true);

        $this->manager()->prune('nara-core', 0);

        $this->assertSame(array('manual-copy', 'notes.txt'), $this->names('deployward-backups-oldsuffix', 'nara-core'));
        $this->assertCount(1, $this->names('deployward-backups-oldsuffix', 'other-plugin'));
        $this->assertDirectoryExists($this->tmp . '/uploads/deployward-work-oldsuffix/dw-extract-1');
        $this->assertDirectoryExists($this->tmp . '/uploads/deployward-backups-oldsuffix.bak/nara-core/20260101-000001-aaaaaaaa');
        $this->assertDirectoryExists($this->tmp . '/uploads/some-other-plugin/nara-core/20260101-000001-aaaaaaaa');
    }

    public function test_prune_never_follows_a_symlinked_suffix_directory(): void
    {
        $outside = $this->tmp . '/outside';
        mkdir($outside . '/nara-core/20260101-000001-aaaaaaaa', 0777, true);
        mkdir($this->tmp . '/uploads', 0777, true);
        symlink($outside, $this->tmp . '/uploads/deployward-backups-linked');

        $this->manager()->prune('nara-core', 0);

        $this->assertDirectoryExists($outside . '/nara-core/20260101-000001-aaaaaaaa');
    }

    public function test_a_slug_that_climbs_out_of_the_backup_directory_is_ignored(): void
    {
        mkdir($this->tmp . '/uploads/escape/20260101-000001-aaaaaaaa', 0777, true);
        mkdir($this->tmp . '/uploads/deployward-backups-newsuffix', 0777, true);

        $manager = $this->manager();
        $manager->prune('../escape', 0);

        $this->assertNull($manager->latest('../escape'));
        $this->assertDirectoryExists($this->tmp . '/uploads/escape/20260101-000001-aaaaaaaa');
    }

    public function test_restore_never_deletes_the_real_files_behind_a_symlinked_target(): void
    {
        $real = $this->tmp . '/real-plugin-checkout';
        mkdir($real, 0777, true);
        file_put_contents($real . '/dev-work.txt', 'uncommitted work');
        mkdir($this->tmp . '/plugins', 0777, true);
        symlink($real, $this->tmp . '/plugins/nara-core');
        $this->makeBackup('deployward-backups-newsuffix', 'nara-core', '20260101-000001-aaaaaaaa', 'backup');

        $result = $this->manager()->restoreLatest('nara-core', $this->tmp . '/plugins/nara-core');

        $this->assertTrue($result->isOk(), $result->message());
        $this->assertFileExists($real . '/dev-work.txt');
        $this->assertSame('backup', file_get_contents($this->tmp . '/plugins/nara-core/version.txt'));
    }

    public function test_a_lazy_backup_directory_is_resolved_on_first_use_not_at_construction(): void
    {
        $calls = 0;
        $manager = new BackupManager(function () use (&$calls) {
            $calls++;

            return $this->tmp . '/uploads/deployward-backups-lazy';
        }, new DirectoryMover());
        $this->assertSame(0, $calls);

        $manager->latest('nara-core');
        $manager->latest('nara-core');

        $this->assertSame(1, $calls);
    }
}
