<?php

namespace Deployward\Deploy;

use Deployward\Support\Result;

final class Extractor
{
    /** @var string */
    private $workBaseDir;

    public function __construct(string $workBaseDir)
    {
        $this->workBaseDir = rtrim($workBaseDir, '/');
    }

    public function extract(string $zipFile): Result
    {
        if (! function_exists('unzip_file')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (! \WP_Filesystem()) {
            return Result::fail('Could not initialise WP_Filesystem');
        }
        $dest = $this->workBaseDir . '/dw-extract-' . wp_generate_password(8, false);
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
}
