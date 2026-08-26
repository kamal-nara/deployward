<?php

namespace Deployward\GitHub;

use Deployward\Support\Result;

final class GitHubClient implements GitHubClientInterface
{
    private const API = 'https://api.github.com';
    private const EXPIRY_HEADER = 'github-authentication-token-expiration';
    private const HTTP_UNAUTHORIZED = 401;
    private const HTTP_NOT_FOUND = 404;

    /** @var string */
    private $lastTokenExpiry = '';

    public function lastTokenExpiry(): string
    {
        return $this->lastTokenExpiry;
    }

    public function resolveSha(string $repo, string $ref, ?string $token): Result
    {
        $this->lastTokenExpiry = '';
        $url = self::API . '/repos/' . $repo . '/commits/' . rawurlencode($ref);
        $response = wp_remote_get($url, $this->args($token, 30));
        if (is_wp_error($response)) {
            return Result::fail('GitHub request failed: ' . $response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return $this->httpFailure($code, $repo, $token, 'GitHub returned HTTP ' . $code . ' resolving ' . $repo . '@' . $ref);
        }
        $this->rememberTokenExpiry($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (! is_array($body) || empty($body['sha'])) {
            return Result::fail('GitHub response did not include a commit sha');
        }

        return Result::ok((string) $body['sha']);
    }

    public function downloadZipball(string $repo, string $ref, ?string $token, string $destFile): Result
    {
        $url = self::API . '/repos/' . $repo . '/zipball/' . rawurlencode($ref);
        $args = array_merge(
            $this->args($token, 300),
            array('stream' => true, 'filename' => $destFile)
        );
        $response = wp_remote_get($url, $args);
        if (is_wp_error($response)) {
            return Result::fail('Download failed: ' . $response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return $this->httpFailure($code, $repo, $token, 'Download returned HTTP ' . $code);
        }
        if (! is_file($destFile) || filesize($destFile) < 1) {
            return Result::fail('Downloaded archive is empty');
        }

        return Result::ok($destFile);
    }

    public function listBranches(string $repo, ?string $token): Result
    {
        $this->lastTokenExpiry = '';
        $url = self::API . '/repos/' . $repo . '/branches?per_page=100';
        $response = wp_remote_get($url, $this->args($token, 30));
        if (is_wp_error($response)) {
            return Result::fail('GitHub request failed: ' . $response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return $this->httpFailure($code, $repo, $token, 'GitHub returned HTTP ' . $code . ' listing branches for ' . $repo);
        }
        $this->rememberTokenExpiry($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (! is_array($body)) {
            return Result::fail('GitHub branches response was not a list');
        }
        $names = array();
        foreach ($body as $branch) {
            if (isset($branch['name'])) {
                $names[] = (string) $branch['name'];
            }
        }

        return Result::ok($names);
    }

    private function httpFailure(int $code, string $repo, ?string $token, string $message): Result
    {
        $authenticated = $token !== null && $token !== '';
        if ($code === self::HTTP_UNAUTHORIZED) {
            return Result::fail(
                'GitHub rejected the token for ' . $repo . ' (HTTP 401): it has expired or been revoked. '
                . 'Replace it under Deployward > Deployments > Edit, then deploy again.',
                $code
            );
        }
        if ($code === self::HTTP_NOT_FOUND && $authenticated) {
            return Result::fail($message . ' (the token may lack access to this repository)', $code);
        }

        return Result::fail($message, $code);
    }

    private function rememberTokenExpiry($response): void
    {
        $this->lastTokenExpiry = self::parseExpiry((string) wp_remote_retrieve_header($response, self::EXPIRY_HEADER));
    }

    public static function parseExpiry(string $raw): string
    {
        $value = trim($raw);
        if ($value === '') {
            return '';
        }
        foreach (array('Y-m-d H:i:s T', 'Y-m-d H:i:s P') as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $value);
            if ($parsed instanceof \DateTimeImmutable) {
                return self::parsedCleanly() ? self::toUtcIso($parsed) : '';
            }
        }
        try {
            $parsed = new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            return '';
        }

        return self::parsedCleanly() ? self::toUtcIso($parsed) : '';
    }

    private static function parsedCleanly(): bool
    {
        $errors = \DateTimeImmutable::getLastErrors();
        if (! is_array($errors)) {
            return true;
        }
        $warnings = isset($errors['warning_count']) ? (int) $errors['warning_count'] : 0;
        $failures = isset($errors['error_count']) ? (int) $errors['error_count'] : 0;

        return $warnings === 0 && $failures === 0;
    }

    private static function toUtcIso(\DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
    }

    private function args(?string $token, int $timeout): array
    {
        $headers = array(
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'Deployward',
            'X-GitHub-Api-Version' => '2022-11-28',
        );
        if ($token !== null && $token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return array('headers' => $headers, 'timeout' => $timeout);
    }
}
