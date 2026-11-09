<?php

namespace Deployward\Deploy;

use Deployward\Support\Result;

final class BackupManager implements BackupManagerInterface
{
    private const ROOT_PREFIX = 'deployward-backups-';
    private const ROOT_PATTERN = '/^deployward-backups-[A-Za-z0-9]+$/';
    private const SLUG_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]*$/';
    private const BACKUP_PATTERN = '/^\d{8}-\d{6}-[0-9a-fA-F]+$/';

    /** @var LazyPath */
    private $base;
    /** @var DirectoryMoverInterface */
    private $mover;

    public function __construct($baseDir, DirectoryMoverInterface $mover)
    {
        $this->base = new LazyPath($baseDir);
        $this->mover = $mover;
    }

    public function ensureProtected(): void
    {
        if (! is_dir($this->base->get()) && ! wp_mkdir_p($this->base->get())) {
            return;
        }
        $index = $this->base->get() . '/index.php';
        if (! is_file($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n");
        }
        $htaccess = $this->base->get() . '/.htaccess';
        if (! is_file($htaccess)) {
            file_put_contents($htaccess, "Deny from all\n");
        }
    }

    public function backup(string $targetDir, string $slug, string $sha): Result
    {
        if (! is_dir($targetDir)) {
            return Result::skip('Nothing to back up; target does not exist yet');
        }
        $this->ensureProtected();
        $slugDir = $this->base->get() . '/' . $slug;
        if (! wp_mkdir_p($slugDir)) {
            return Result::fail('Could not create backup directory ' . $slugDir . ' (check that the uploads directory is writable)');
        }
        $dest = $slugDir . '/' . gmdate('Ymd-His') . '-' . substr($sha, 0, 8);
        $moved = $this->mover->move($targetDir, $dest);
        if (! $moved->isOk()) {
            return Result::fail('Could not move the current version into backups: ' . $moved->message());
        }

        return Result::ok($dest);
    }

    public function restoreLatest(string $slug, string $targetDir): Result
    {
        $latest = $this->latest($slug);
        if ($latest === null) {
            return Result::fail('No backup available to restore');
        }
        $this->ensureProtected();
        $sidecar = $this->base->get() . '/.restore-tmp-' . $slug . '-' . gmdate('YmdHis');
        $hadTarget = is_dir($targetDir);
        if ($hadTarget) {
            $setAside = $this->mover->move($targetDir, $sidecar);
            if (! $setAside->isOk()) {
                return Result::fail('Could not set aside the current version for restore: ' . $setAside->message());
            }
        }
        $restored = $this->mover->move($latest, $targetDir);
        if (! $restored->isOk()) {
            if ($hadTarget) {
                $this->mover->move($sidecar, $targetDir);
            }
            return Result::fail('Could not restore backup into place: ' . $restored->message());
        }
        if ($hadTarget) {
            $this->deleteDir($sidecar);
        }

        return Result::ok($targetDir);
    }

    public function latest(string $slug): ?string
    {
        $backups = $this->sortedBackups($slug);

        return $backups === array() ? null : $backups[0];
    }

    public function prune(string $slug, int $keep): void
    {
        $stale = array_slice($this->sortedBackups($slug), max(0, $keep));
        foreach ($stale as $dir) {
            $this->deleteDir($dir);
        }
    }

    private function sortedBackups(string $slug): array
    {
        if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            return array();
        }
        $found = array();
        foreach ($this->backupRoots() as $root) {
            $found = array_merge($found, $this->backupsIn($root . '/' . $slug));
        }
        usort($found, function (string $a, string $b): int {
            $byName = strcmp(basename($b), basename($a));

            return $byName !== 0 ? $byName : strcmp($b, $a);
        });

        return $found;
    }

    private function backupRoots(): array
    {
        $roots = array($this->base->get());
        $entries = is_dir(dirname($this->base->get())) ? scandir(dirname($this->base->get())) : false;
        foreach ($entries === false ? array() : $entries as $entry) {
            $path = dirname($this->base->get()) . '/' . $entry;
            $isLegacyRoot = preg_match(self::ROOT_PATTERN, $entry) === 1 && is_dir($path) && ! is_link($path);
            if ($isLegacyRoot && $path !== $this->base->get()) {
                $roots = array_merge($roots, array($path));
            }
        }

        return $roots;
    }

    private function backupsIn(string $slugDir): array
    {
        if (! is_dir($slugDir) || is_link($slugDir)) {
            return array();
        }
        $dirs = array();
        foreach (array_diff(scandir($slugDir), array('.', '..')) as $entry) {
            $path = $slugDir . '/' . $entry;
            if (preg_match(self::BACKUP_PATTERN, $entry) === 1 && is_dir($path) && ! is_link($path)) {
                $dirs = array_merge($dirs, array($path));
            }
        }

        return $dirs;
    }
    private function deleteDir(string $dir): void
    {
        if (is_link($dir)) {
            @unlink($dir);
            return;
        }
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isLink() || ! $item->isDir()) {
                @unlink($item->getPathname());
            } else {
                @rmdir($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
