<?php

namespace Deployward\Tests\Unit\Deploy;

use Deployward\Deploy\PayloadValidator;
use PHPUnit\Framework\TestCase;

final class PayloadValidatorTest extends TestCase
{
    private $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/dw-validate-' . uniqid();
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        @exec('rm -rf ' . escapeshellarg($this->tmp));
        parent::tearDown();
    }

    public function test_accepts_plugin_with_header(): void
    {
        file_put_contents($this->tmp . '/nara-core.php', "<?php\n/**\n * Plugin Name: Nara Core\n */");

        $result = (new PayloadValidator())->validate($this->tmp, 'plugin', 'nara-core');

        $this->assertTrue($result->isOk());
    }

    public function test_rejects_plugin_without_header(): void
    {
        file_put_contents($this->tmp . '/nara-core.php', "<?php\n// nothing here");

        $result = (new PayloadValidator())->validate($this->tmp, 'plugin', 'nara-core');

        $this->assertFalse($result->isOk());
    }

    public function test_accepts_theme_with_style_header(): void
    {
        file_put_contents($this->tmp . '/style.css', "/*\nTheme Name: Nara\n*/");

        $result = (new PayloadValidator())->validate($this->tmp, 'theme', 'nara');

        $this->assertTrue($result->isOk());
    }
}
