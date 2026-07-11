<?php

namespace Deployward\Deploy;

use Deployward\Support\Result;

final class Extractor implements ExtractorInterface
{
    private const EXTRACT_PREFIX = 'dw-extract-';

    /** @var string */
    private $workBaseDir;

    public function __construct(string $workBaseDir)
    {
        $this->workBaseDir = rtrim($workBaseDir, '/');
    }

    public function extract(string $zipFile): Result
    {
        if (! wp_mkdir_p($this->workBaseDir)) {
            return Result::fail('Could not create work directory ' . $this->workBaseDir . ' (check that the uploads directory is writable)');
        }
        $guard = $this->workBaseDir . '/index.php';
        if (! is_file($guard)) {
            @file_put_contents($guard, "<?php\n// Silence is golden.\n");
        }

        if (! function_exists('unzip_file')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (! \WP_Filesystem()) {
            return Result::fail('Could not initialise WP_Filesystem');
        }
        $dest = $this->workBaseDir . '/' . self::EXTRACT_PREFIX . wp_generate_password(8, false);
        $unzipped = unzip_file($zipFile, $dest);
        if (is_wp_error($unzipped)) {
            return Result::fail('Unzip failed: ' . $unzipped->get_error_message());
        }
        $root = $this->flatten($dest);
        if ($root === '' || ! is_dir($root)) {
            return Result::fail('Unexpected archive layout');
        }

        return Result::ok($root);
    }

    public function flatten(string $extractedDir): string
    {
        $entries = scandir($extractedDir);
        if ($entries === false) {
            return '';
        }
        $entries = array_values(array_diff($entries, array('.', '..')));
        if (count($entries) === 1 && is_dir($extractedDir . '/' . $entries[0])) {
            return $extractedDir . '/' . $entries[0];
        }

        return $extractedDir;
    }

    public function cleanup(string $extractedRoot): void
    {
        $container = $this->extractContainer($extractedRoot);
        if ($container === null || ! is_dir($container)) {
            return;
        }
        $this->deleteTree($container);
    }

    private function extractContainer(string $extractedRoot): ?string
    {
        if (strpos(basename($extractedRoot), self::EXTRACT_PREFIX) === 0) {
            return $extractedRoot;
        }
        if (strpos(basename(dirname($extractedRoot)), self::EXTRACT_PREFIX) === 0) {
            return dirname($extractedRoot);
        }

        return null;
    }

    private function deleteTree(string $dir): void
    {
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
