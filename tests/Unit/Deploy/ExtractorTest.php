<?php

namespace Deployward\Tests\Unit\Deploy;

use Deployward\Deploy\Extractor;
use PHPUnit\Framework\TestCase;

final class ExtractorTest extends TestCase
{
    private $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/dw-extract-' . uniqid();
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        @exec('rm -rf ' . escapeshellarg($this->tmp));
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
}
