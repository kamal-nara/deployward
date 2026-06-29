<?php

namespace Deployward\Tests\Unit\GitHub;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\GitHub\GitHubClient;
use PHPUnit\Framework\TestCase;

final class GitHubClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_resolve_sha_returns_commit_sha(): void
    {
        Functions\when('wp_remote_get')->justReturn(array('body' => '{"sha":"abc123"}'));
        Functions\when('wp_remote_retrieve_body')->justReturn('{"sha":"abc123"}');

        $result = (new GitHubClient())->resolveSha('Nara-IT/nara-core', 'main', 'ghp_x');

        $this->assertTrue($result->isOk());
        $this->assertSame('abc123', $result->data());
    }

    public function test_resolve_sha_fails_on_non_200(): void
    {
        Functions\when('wp_remote_retrieve_response_code')->justReturn(404);
        Functions\when('wp_remote_get')->justReturn(array());
        Functions\when('wp_remote_retrieve_body')->justReturn('');

        $result = (new GitHubClient())->resolveSha('Nara-IT/missing', 'main', null);

        $this->assertFalse($result->isOk());
    }

    public function test_download_zipball_writes_file_and_returns_path(): void
    {
        $dest = sys_get_temp_dir() . '/dw-zip-' . uniqid() . '.zip';
        Functions\when('wp_remote_get')->alias(function ($url, $args = array()) {
            if (isset($args['filename'])) {
                file_put_contents($args['filename'], 'ZIPDATA');
            }
            return array();
        });
        Functions\when('wp_remote_retrieve_body')->justReturn('');

        $result = (new GitHubClient())->downloadZipball('Nara-IT/nara-core', 'main', 'ghp_x', $dest);

        $this->assertTrue($result->isOk());
        $this->assertSame($dest, $result->data());
        @unlink($dest);
    }

    public function test_download_zipball_fails_on_non_200(): void
    {
        $dest = sys_get_temp_dir() . '/dw-zip-' . uniqid() . '.zip';
        Functions\when('wp_remote_retrieve_response_code')->justReturn(500);
        Functions\when('wp_remote_get')->justReturn(array());
        Functions\when('wp_remote_retrieve_body')->justReturn('');

        $result = (new GitHubClient())->downloadZipball('Nara-IT/nara-core', 'main', null, $dest);

        $this->assertFalse($result->isOk());
        @unlink($dest);
    }

    public function test_download_zipball_fails_when_file_missing(): void
    {
        $dest = sys_get_temp_dir() . '/dw-zip-' . uniqid() . '.zip';
        Functions\when('wp_remote_get')->justReturn(array());
        Functions\when('wp_remote_retrieve_body')->justReturn('');

        $result = (new GitHubClient())->downloadZipball('Nara-IT/nara-core', 'main', null, $dest);

        $this->assertFalse($result->isOk());
    }

    public function test_resolve_sha_fails_on_wp_error(): void
    {
        Functions\when('is_wp_error')->justReturn(true);
        Functions\when('wp_remote_get')->justReturn(new class {
            public function get_error_message()
            {
                return 'dns failure';
            }
        });

        $result = (new GitHubClient())->resolveSha('Nara-IT/nara-core', 'main', null);

        $this->assertFalse($result->isOk());
    }

    public function test_download_zipball_fails_on_wp_error(): void
    {
        $dest = sys_get_temp_dir() . '/dw-zip-' . uniqid() . '.zip';
        Functions\when('is_wp_error')->justReturn(true);
        Functions\when('wp_remote_get')->justReturn(new class {
            public function get_error_message()
            {
                return 'connection refused';
            }
        });

        $result = (new GitHubClient())->downloadZipball('Nara-IT/nara-core', 'main', null, $dest);

        $this->assertFalse($result->isOk());
    }
}
