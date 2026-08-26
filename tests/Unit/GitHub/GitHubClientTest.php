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
        Functions\when('wp_remote_retrieve_header')->justReturn('');
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

    public function test_list_branches_returns_names(): void
    {
        Functions\when('wp_remote_get')->justReturn(array());
        Functions\when('wp_remote_retrieve_body')->justReturn('[{"name":"main"},{"name":"develop"}]');

        $result = (new GitHubClient())->listBranches('Nara-IT/nara-core', 'ghp_x');

        $this->assertTrue($result->isOk());
        $this->assertSame(array('main', 'develop'), $result->data());
    }

    public function test_list_branches_fails_on_non_200(): void
    {
        Functions\when('wp_remote_retrieve_response_code')->justReturn(404);
        Functions\when('wp_remote_get')->justReturn(array());
        Functions\when('wp_remote_retrieve_body')->justReturn('');

        $result = (new GitHubClient())->listBranches('Nara-IT/missing', null);

        $this->assertFalse($result->isOk());
    }

    public function test_resolve_sha_reads_the_token_expiry_header(): void
    {
        Functions\when('wp_remote_get')->justReturn(array());
        Functions\when('wp_remote_retrieve_body')->justReturn('{"sha":"abc123"}');
        Functions\when('wp_remote_retrieve_header')->justReturn('2026-09-26 12:34:56 UTC');

        $client = new GitHubClient();
        $client->resolveSha('Nara-IT/nara-core', 'main', 'ghp_x');

        $this->assertSame('2026-09-26T12:34:56+00:00', $client->lastTokenExpiry());
    }

    public function test_resolve_sha_accepts_an_iso_expiry_header(): void
    {
        Functions\when('wp_remote_get')->justReturn(array());
        Functions\when('wp_remote_retrieve_body')->justReturn('{"sha":"abc123"}');
        Functions\when('wp_remote_retrieve_header')->justReturn('2026-09-26T12:34:56+00:00');

        $client = new GitHubClient();
        $client->resolveSha('Nara-IT/nara-core', 'main', 'ghp_x');

        $this->assertSame('2026-09-26T12:34:56+00:00', $client->lastTokenExpiry());
    }

    public function test_resolve_sha_accepts_a_numeric_offset_expiry_header(): void
    {
        Functions\when('wp_remote_get')->justReturn(array());
        Functions\when('wp_remote_retrieve_body')->justReturn('{"sha":"abc123"}');
        Functions\when('wp_remote_retrieve_header')->justReturn('2026-09-26 14:34:56 +0200');

        $client = new GitHubClient();
        $client->resolveSha('Nara-IT/nara-core', 'main', 'ghp_x');

        $this->assertSame('2026-09-26T12:34:56+00:00', $client->lastTokenExpiry());
    }

    public function test_a_missing_expiry_header_leaves_the_expiry_empty(): void
    {
        Functions\when('wp_remote_get')->justReturn(array());
        Functions\when('wp_remote_retrieve_body')->justReturn('{"sha":"abc123"}');

        $client = new GitHubClient();
        $client->resolveSha('Nara-IT/nara-core', 'main', 'ghp_x');

        $this->assertSame('', $client->lastTokenExpiry());
    }

    public function test_a_garbage_expiry_header_leaves_the_expiry_empty(): void
    {
        Functions\when('wp_remote_get')->justReturn(array());
        Functions\when('wp_remote_retrieve_body')->justReturn('{"sha":"abc123"}');
        Functions\when('wp_remote_retrieve_header')->justReturn('whenever it feels like it');

        $client = new GitHubClient();
        $client->resolveSha('Nara-IT/nara-core', 'main', 'ghp_x');

        $this->assertSame('', $client->lastTokenExpiry());
    }

    public function test_list_branches_reads_the_token_expiry_header(): void
    {
        Functions\when('wp_remote_get')->justReturn(array());
        Functions\when('wp_remote_retrieve_body')->justReturn('[{"name":"main"}]');
        Functions\when('wp_remote_retrieve_header')->justReturn('2026-09-26 12:34:56 UTC');

        $client = new GitHubClient();
        $client->listBranches('Nara-IT/nara-core', 'ghp_x');

        $this->assertSame('2026-09-26T12:34:56+00:00', $client->lastTokenExpiry());
    }

    public function test_a_failed_call_clears_the_remembered_expiry(): void
    {
        Functions\when('wp_remote_get')->justReturn(array());
        Functions\when('wp_remote_retrieve_body')->justReturn('{"sha":"abc123"}');
        Functions\when('wp_remote_retrieve_header')->justReturn('2026-09-26 12:34:56 UTC');
        $client = new GitHubClient();
        $client->resolveSha('Nara-IT/nara-core', 'main', 'ghp_x');

        Functions\when('wp_remote_retrieve_response_code')->justReturn(401);
        $result = $client->resolveSha('Nara-IT/nara-core', 'main', 'ghp_x');

        $this->assertSame('', $client->lastTokenExpiry());
        $this->assertSame(401, $result->data());
    }

    public function test_a_401_says_the_token_expired_and_where_to_replace_it(): void
    {
        Functions\when('wp_remote_retrieve_response_code')->justReturn(401);
        Functions\when('wp_remote_get')->justReturn(array());
        Functions\when('wp_remote_retrieve_body')->justReturn('');

        $result = (new GitHubClient())->resolveSha('Nara-IT/nara-core', 'main', 'ghp_expired');

        $this->assertStringContainsString('GitHub rejected the token for Nara-IT/nara-core (HTTP 401)', $result->message());
        $this->assertStringContainsString('expired or been revoked', $result->message());
        $this->assertStringContainsString('Deployward > Deployments > Edit', $result->message());
    }

    public function test_a_401_on_a_download_says_the_same_thing(): void
    {
        Functions\when('wp_remote_retrieve_response_code')->justReturn(401);
        Functions\when('wp_remote_get')->justReturn(array());
        Functions\when('wp_remote_retrieve_body')->justReturn('');

        $result = (new GitHubClient())->downloadZipball('Nara-IT/nara-core', 'main', 'ghp_expired', '/tmp/dw.zip');

        $this->assertStringContainsString('GitHub rejected the token for Nara-IT/nara-core (HTTP 401)', $result->message());
    }

    public function test_a_401_listing_branches_says_the_same_thing(): void
    {
        Functions\when('wp_remote_retrieve_response_code')->justReturn(401);
        Functions\when('wp_remote_get')->justReturn(array());
        Functions\when('wp_remote_retrieve_body')->justReturn('');

        $result = (new GitHubClient())->listBranches('Nara-IT/nara-core', 'ghp_expired');

        $this->assertStringContainsString('GitHub rejected the token for Nara-IT/nara-core (HTTP 401)', $result->message());
    }

    public function test_a_404_with_a_token_hints_at_repository_access(): void
    {
        Functions\when('wp_remote_retrieve_response_code')->justReturn(404);
        Functions\when('wp_remote_get')->justReturn(array());
        Functions\when('wp_remote_retrieve_body')->justReturn('');

        $result = (new GitHubClient())->resolveSha('Nara-IT/nara-core', 'main', 'ghp_x');

        $this->assertStringContainsString('GitHub returned HTTP 404 resolving Nara-IT/nara-core@main', $result->message());
        $this->assertStringContainsString('the token may lack access to this repository', $result->message());
    }

    public function test_parse_expiry_normalises_a_github_header_to_utc(): void
    {
        $this->assertSame(
            '2027-09-26T12:34:56+00:00',
            GitHubClient::parseExpiry('2027-09-26 12:34:56 UTC')
        );
    }

    public function test_parse_expiry_rejects_a_date_that_only_parses_by_overflowing(): void
    {
        $this->assertSame('', GitHubClient::parseExpiry('2026-13-45 12:00:00 UTC'));
    }

    public function test_parse_expiry_returns_nothing_for_an_absent_header(): void
    {
        $this->assertSame('', GitHubClient::parseExpiry(''));
    }

    public function test_a_404_without_a_token_keeps_the_plain_message(): void
    {
        Functions\when('wp_remote_retrieve_response_code')->justReturn(404);
        Functions\when('wp_remote_get')->justReturn(array());
        Functions\when('wp_remote_retrieve_body')->justReturn('');

        $result = (new GitHubClient())->resolveSha('Nara-IT/missing', 'main', null);

        $this->assertSame('GitHub returned HTTP 404 resolving Nara-IT/missing@main', $result->message());
    }
}
