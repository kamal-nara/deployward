<?php

namespace Deployward\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SaltIndependenceTest extends TestCase
{
    const ONLY_ALLOWED_FILE = 'Security/KeyRing.php';
    const SALT_PATTERN = '/AUTH_SALT|AUTH_KEY|SECURE_AUTH|LOGGED_IN_(KEY|SALT)|NONCE_(KEY|SALT)|wp_salt\s*\(/';

    private static function sourceRoot(): string
    {
        return dirname(__DIR__, 2) . '/src';
    }

    private static function sourceFiles(): array
    {
        $root = self::sourceRoot();
        $files = array();
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files = array_merge($files, array(substr($file->getPathname(), strlen($root) + 1)));
            }
        }
        sort($files);

        return $files;
    }

    public function test_only_the_key_ring_legacy_decrypt_reads_the_wordpress_salts(): void
    {
        $readers = array();
        foreach (self::sourceFiles() as $relative) {
            $code = file_get_contents(self::sourceRoot() . '/' . $relative);
            if (preg_match(self::SALT_PATTERN, $code) === 1) {
                $readers = array_merge($readers, array($relative));
            }
        }

        $this->assertSame(array(self::ONLY_ALLOWED_FILE), $readers);
    }

    public function test_the_key_ring_reads_the_salts_only_to_build_the_legacy_key(): void
    {
        $code = file_get_contents(self::sourceRoot() . '/Security/KeyRing.php');
        $this->assertSame(1, preg_match('/public static function fromConstants\(.*?\n    }\n/s', $code, $method));

        $outsideThatMethod = str_replace($method[0], '', $code);

        $this->assertSame(1, preg_match(self::SALT_PATTERN, $method[0]));
        $this->assertSame(0, preg_match(self::SALT_PATTERN, $outsideThatMethod));
    }
}
