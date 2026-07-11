<?php

namespace Deployward\Tests\Unit\Deploy;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\Deploy\HealthChecker;
use PHPUnit\Framework\TestCase;

final class HealthCheckerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('is_wp_error')->justReturn(false);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_healthy_on_200_clean_body(): void
    {
        Functions\when('wp_remote_get')->justReturn(array());
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('<html>ok</html>');

        $this->assertTrue((new HealthChecker())->check('https://nara.local/')->isOk());
    }

    public function test_check_busts_full_page_caches_with_a_unique_url(): void
    {
        $requestedUrl = null;
        Functions\when('wp_remote_get')->alias(function ($url) use (&$requestedUrl) {
            $requestedUrl = $url;

            return array();
        });
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('<html>ok</html>');

        (new HealthChecker())->check('https://nara.local/');

        $this->assertStringContainsString('dw_health=', (string) $requestedUrl);
        $this->assertStringStartsWith('https://nara.local/?dw_health=', (string) $requestedUrl);
    }

    public function test_check_appends_cache_buster_with_ampersand_when_url_has_query(): void
    {
        $requestedUrl = null;
        Functions\when('wp_remote_get')->alias(function ($url) use (&$requestedUrl) {
            $requestedUrl = $url;

            return array();
        });
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('<html>ok</html>');

        (new HealthChecker())->check('https://nara.local/?foo=bar');

        $this->assertStringContainsString('&dw_health=', (string) $requestedUrl);
    }

    public function test_unhealthy_on_500(): void
    {
        Functions\when('wp_remote_get')->justReturn(array());
        Functions\when('wp_remote_retrieve_response_code')->justReturn(500);
        Functions\when('wp_remote_retrieve_body')->justReturn('');

        $this->assertFalse((new HealthChecker())->check('https://nara.local/')->isOk());
    }

    public function test_unhealthy_on_fatal_signature(): void
    {
        Functions\when('wp_remote_get')->justReturn(array());
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('There has been a critical error on this website.');

        $this->assertFalse((new HealthChecker())->check('https://nara.local/')->isOk());
    }

    public function test_unhealthy_on_wp_error(): void
    {
        Functions\when('is_wp_error')->justReturn(true);
        Functions\when('wp_remote_get')->justReturn(new class {
            public function get_error_message()
            {
                return 'timeout';
            }
        });

        $this->assertFalse((new HealthChecker())->check('https://nara.local/')->isOk());
    }
}
