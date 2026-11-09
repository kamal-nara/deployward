<?php

namespace Deployward\Deploy;

use Deployward\Support\Result;

final class Extractor implements ExtractorInterface
{
    private const EXTRACT_PREFIX = 'dw-extract-';

    /** @var LazyPath */
    private $base;

    public function __construct($workBaseDir)
    {
        $this->base = new LazyPath($workBaseDir);
    }

    public function extract(string $zipFile): Result
    {
        if (! wp_mkdir_p($this->base->get())) {
            return Result::fail('Could not create work directory ' . $this->base->get() . ' (check that the uploads directory is writable)');
        }
        $guard = $this->base->get() . '/index.php';
        if (! is_file($guard)) {
            @file_put_contents($guard, "<?php\n// Silence is golden.\n");
        }

        if (! function_exists('unzip_file')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (! \WP_Filesystem()) {
            return Result::fail('Could not initialise WP_Filesystem');
        }
        $dest = $this->base->get() . '/' . self::EXTRACT_PREFIX . wp_generate_password(8, false);
        $unzipped = unzip_file($zipFile, $dest);
        if (is_wp_error($unzipped)) {
            $this->deleteTree($dest);
            return Result::fail('Unzip failed: ' . $unzipped->get_error_message());
        }
        $root = $this->flatten($dest);
        if ($root === '' || ! is_dir($root)) {
            $this->deleteTree($dest);
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
        if ($container === null || ! is_dir($container) || dirname($container) !== $this->base->get()) {
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
        if (is_link($dir)) {
            @unlink($dir);
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
