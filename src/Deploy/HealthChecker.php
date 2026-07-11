<?php

namespace Deployward\Deploy;

use Deployward\Support\Result;

final class HealthChecker implements HealthCheckerInterface
{
    public function check(string $url): Result
    {
        $bustedUrl = $url . (strpos($url, '?') === false ? '?' : '&') . 'dw_health=' . rawurlencode(uniqid('', true));
        $response = wp_remote_get($bustedUrl, array(
            'timeout' => 20,
            'redirection' => 2,
            'sslverify' => true,
        ));
        if (is_wp_error($response)) {
            return Result::fail('Health check error: ' . $response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code >= 500) {
            return Result::fail('Site returned HTTP ' . $code);
        }
        $body = (string) wp_remote_retrieve_body($response);
        if (stripos($body, 'There has been a critical error') !== false
            || stripos($body, 'Fatal error') !== false) {
            return Result::fail('Detected a fatal error in the page output');
        }

        return Result::ok($code);
    }
}
