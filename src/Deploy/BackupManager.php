<?php

namespace Deployward\Deploy;

use Deployward\Support\Result;

final class BackupManager
{
    /** @var string */
    private $baseDir;

    public function __construct(string $baseDir)
    {
        $this->baseDir = rtrim($baseDir, '/');
    }

    public function ensureProtected(): void
    {
        if (! is_dir($this->baseDir)) {
            wp_mkdir_p($this->baseDir);
        }
        $index = $this->baseDir . '/index.php';
        if (! is_file($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n");
        }
        $htaccess = $this->baseDir . '/.htaccess';
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
        $slugDir = $this->baseDir . '/' . $slug;
        wp_mkdir_p($slugDir);
        $dest = $slugDir . '/' . gmdate('Ymd-His') . '-' . substr($sha, 0, 8);
        if (! @rename($targetDir, $dest)) {
            return Result::fail('Could not move current version into backups');
        }

        return Result::ok($dest);
    }

    public function restoreLatest(string $slug, string $targetDir): Result
    {
        $latest = $this->latest($slug);
        if ($latest === null) {
            return Result::fail('No backup available to restore');
        }
        $sidecar = $this->baseDir . '/.restore-tmp-' . $slug . '-' . gmdate('YmdHis');
        $hadTarget = is_dir($targetDir);
        if ($hadTarget && ! @rename($targetDir, $sidecar)) {
            return Result::fail('Could not set aside the current version for restore');
        }
        if (! @rename($latest, $targetDir)) {
            if ($hadTarget) {
                @rename($sidecar, $targetDir);
            }
            return Result::fail('Could not restore backup into place');
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
        $slugDir = $this->baseDir . '/' . $slug;
        if (! is_dir($slugDir)) {
            return array();
        }
        $entries = array_values(array_diff(scandir($slugDir), array('.', '..')));
        $dirs = array();
        foreach ($entries as $entry) {
            $path = $slugDir . '/' . $entry;
            if (is_dir($path)) {
                $dirs[] = $path;
            }
        }
        rsort($dirs);

        return $dirs;
    }

    private function deleteDir(string $dir): void
    {
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
