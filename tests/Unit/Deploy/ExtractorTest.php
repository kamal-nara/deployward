<?php

namespace Deployward\Tests\Unit\Deploy;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\Deploy\Extractor;
use PHPUnit\Framework\TestCase;

final class ExtractorTest extends TestCase
{
    private $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->tmp = sys_get_temp_dir() . '/dw-extractor-test-' . uniqid();
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        @exec('rm -rf ' . escapeshellarg($this->tmp));
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_flatten_returns_single_wrapper_subdir(): void
    {
        $extracted = $this->tmp . '/extracted';
        mkdir($extracted . '/Nara-IT-nara-core-deadbeef', 0777, true);
        file_put_contents($extracted . '/Nara-IT-nara-core-deadbeef/nara-core.php', '<?php');

        $root = (new Extractor($this->tmp))->flatten($extracted);

        $this->assertSame($extracted . '/Nara-IT-nara-core-deadbeef', $root);
    }

    public function test_flatten_returns_dir_when_not_wrapped(): void
    {
        $extracted = $this->tmp . '/flat';
        mkdir($extracted, 0777, true);
        file_put_contents($extracted . '/a.php', '<?php');
        file_put_contents($extracted . '/b.php', '<?php');

        $root = (new Extractor($this->tmp))->flatten($extracted);

        $this->assertSame($extracted, $root);
    }

    public function test_extract_fails_when_work_dir_cannot_be_created(): void
    {
        Functions\when('wp_mkdir_p')->justReturn(false);
        $workDir = $this->tmp . '/does-not-exist';

        $result = (new Extractor($workDir))->extract($this->tmp . '/whatever.zip');

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString($workDir, $result->message());
    }

    public function test_cleanup_removes_the_extract_container(): void
    {
        $container = $this->tmp . '/dw-extract-abc';
        mkdir($container . '/inner', 0777, true);
        file_put_contents($container . '/inner/file.txt', 'x');

        (new Extractor($this->tmp))->cleanup($container . '/inner');

        $this->assertDirectoryDoesNotExist($container);
    }

    public function test_cleanup_refuses_paths_outside_extract_dirs(): void
    {
        $keep = $this->tmp . '/keep';
        mkdir($keep, 0777, true);
        file_put_contents($keep . '/file.txt', 'x');

        (new Extractor($this->tmp))->cleanup($keep);

        $this->assertDirectoryExists($keep);
    }

    public function test_cleanup_never_deletes_an_extract_directory_outside_its_own_work_dir(): void
    {
        $stale = $this->tmp . '/deployward-work-oldsuffix/dw-extract-stale';
        mkdir($stale . '/inner', 0777, true);
        mkdir($this->tmp . '/deployward-work-newsuffix', 0777, true);

        (new Extractor($this->tmp . '/deployward-work-newsuffix'))->cleanup($stale . '/inner');

        $this->assertDirectoryExists($stale . '/inner');
    }

    private function stubUnzipThatFailsHalfway(): void
    {
        Functions\when('wp_mkdir_p')->alias(function ($dir) {
            return is_dir($dir) || mkdir($dir, 0777, true);
        });
        Functions\when('WP_Filesystem')->justReturn(true);
        Functions\when('wp_generate_password')->justReturn('abc12345');
        Functions\when('is_wp_error')->alias(function ($value) {
            return is_object($value) && method_exists($value, 'get_error_message');
        });
        Functions\when('unzip_file')->alias(function ($file, $dest) {
            mkdir($dest . '/half-way/inner', 0777, true);
            file_put_contents($dest . '/half-way/inner/file.txt', 'x');

            return new class {
                public function get_error_message(): string
                {
                    return 'Incompatible Archive.';
                }
            };
        });
    }

    public function test_a_failed_unzip_leaves_no_partial_extract_directory_behind(): void
    {
        $this->stubUnzipThatFailsHalfway();
        $work = $this->tmp . '/deployward-work-current';

        $result = (new Extractor($work))->extract($this->tmp . '/whatever.zip');

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('Unzip failed', $result->message());
        $this->assertDirectoryDoesNotExist($work . '/dw-extract-abc12345');
    }

    public function test_cleanup_removes_a_symlinked_extract_directory_without_touching_its_target(): void
    {
        $work = $this->tmp . '/deployward-work-current';
        $outside = $this->tmp . '/outside';
        mkdir($work, 0777, true);
        mkdir($outside, 0777, true);
        file_put_contents($outside . '/precious.txt', 'keep');
        symlink($outside, $work . '/dw-extract-linked');

        (new Extractor($work))->cleanup($work . '/dw-extract-linked');

        $this->assertFileExists($outside . '/precious.txt');
        $this->assertFalse(is_link($work . '/dw-extract-linked'));
    }

    public function test_a_lazy_work_directory_is_resolved_on_first_use_not_at_construction(): void
    {
        $calls = 0;
        $work = $this->tmp . '/deployward-work-lazy';
        $extractor = new Extractor(function () use (&$calls, $work) {
            $calls++;

            return $work;
        });
        $this->assertSame(0, $calls);
        mkdir($work . '/dw-extract-one', 0777, true);
        mkdir($work . '/dw-extract-two', 0777, true);

        $extractor->cleanup($work . '/dw-extract-one');
        $extractor->cleanup($work . '/dw-extract-two');

        $this->assertSame(1, $calls);
        $this->assertDirectoryDoesNotExist($work . '/dw-extract-one');
        $this->assertDirectoryDoesNotExist($work . '/dw-extract-two');
    }
}
