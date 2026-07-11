<?php

namespace Deployward\Tests\Unit\Deploy;

use Deployward\Deploy\DirectoryMover;
use PHPUnit\Framework\TestCase;

final class DirectoryMoverTest extends TestCase
{
    /** @var string */
    private $sandbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = sys_get_temp_dir() . '/dw-mover-' . uniqid('', true);
        mkdir($this->sandbox, 0777, true);
    }

    protected function tearDown(): void
    {
        @exec('rm -rf ' . escapeshellarg($this->sandbox));
        parent::tearDown();
    }

    public function test_moves_via_rename_when_possible(): void
    {
        $source = $this->sandbox . '/source';
        mkdir($source . '/nested', 0777, true);
        file_put_contents($source . '/nested/file.txt', 'hello');
        $dest = $this->sandbox . '/dest';

        $result = (new DirectoryMover())->move($source, $dest);

        $this->assertTrue($result->isOk());
        $this->assertFileExists($dest . '/nested/file.txt');
        $this->assertDirectoryDoesNotExist($source);
    }

    public function test_falls_back_to_copy_when_rename_fails(): void
    {
        $source = $this->sandbox . '/source';
        mkdir($source . '/sub', 0777, true);
        file_put_contents($source . '/root.txt', 'root-content');
        file_put_contents($source . '/sub/child.txt', 'child-content');
        $dest = $this->sandbox . '/dest';

        $mover = new DirectoryMover(static function (): bool {
            return false;
        });
        $result = $mover->move($source, $dest);

        $this->assertTrue($result->isOk());
        $this->assertSame('root-content', file_get_contents($dest . '/root.txt'));
        $this->assertSame('child-content', file_get_contents($dest . '/sub/child.txt'));
        $this->assertDirectoryDoesNotExist($source);
        $this->assertStringContainsString('copy', $result->message());
    }

    public function test_fails_with_paths_and_reason_when_copy_impossible(): void
    {
        $source = $this->sandbox . '/source';
        mkdir($source, 0777, true);
        file_put_contents($source . '/file.txt', 'data');
        $blocker = $this->sandbox . '/blocker';
        file_put_contents($blocker, 'not a directory');
        $dest = $blocker . '/child';

        $mover = new DirectoryMover(static function (): bool {
            return false;
        });
        $result = $mover->move($source, $dest);

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString($source, $result->message());
        $this->assertStringContainsString($dest, $result->message());
    }
}
