<?php

namespace Deployward\Deploy;

use Deployward\Support\Result;

final class DirectoryMover implements DirectoryMoverInterface
{
    /** @var callable */
    private $renamer;

    public function __construct(?callable $renamer = null)
    {
        $this->renamer = $renamer !== null ? $renamer : static function (string $a, string $b): bool {
            return @rename($a, $b);
        };
    }

    public function move(string $source, string $dest): Result
    {
        $renamer = $this->renamer;
        error_clear_last();
        if ($renamer($source, $dest)) {
            return Result::ok($dest);
        }
        $renameReason = $this->lastErrorReason('cross-filesystem');

        $copied = $this->copyTree($source, $dest);
        if (! $copied->isOk()) {
            return $copied;
        }

        $this->deleteTree($source);

        return Result::ok($dest, 'Moved by copy (rename not possible: ' . $renameReason . ')');
    }

    private function copyTree(string $source, string $dest): Result
    {
        if (! $this->makeDir($dest)) {
            return $this->copyFailure($source, $dest);
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($items as $item) {
            $target = $dest . substr($item->getPathname(), strlen($source));
            $copied = $item->isDir() ? $this->makeDir($target) : $this->copyFile($item->getPathname(), $target);
            if (! $copied) {
                return $this->copyFailure($source, $dest);
            }
        }

        return Result::ok($dest);
    }

    private function copyFile(string $source, string $target): bool
    {
        error_clear_last();

        return @copy($source, $target);
    }

    private function copyFailure(string $source, string $dest): Result
    {
        $reason = $this->lastErrorReason('unknown filesystem error');
        $this->deleteTree($dest);

        return Result::fail('Could not move ' . $source . ' to ' . $dest . ' (' . $reason . ')');
    }

    private function makeDir(string $dir): bool
    {
        if (is_dir($dir)) {
            return true;
        }
        error_clear_last();

        return @mkdir($dir, 0755, true);
    }

    private function lastErrorReason(string $fallback): string
    {
        $error = error_get_last();

        return $error !== null ? $error['message'] : $fallback;
    }

    private function deleteTree(string $dir): void
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
