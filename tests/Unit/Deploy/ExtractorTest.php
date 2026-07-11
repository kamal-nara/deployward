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
}
