<?php

namespace Deployward\Config;

use Deployward\Security\Encryptor;

final class DeploymentRepository implements DeploymentRepositoryInterface
{
    const OPTION = 'deployward_deployments';

    /** @var Encryptor */
    private $encryptor;

    public function __construct(Encryptor $encryptor)
    {
        $this->encryptor = $encryptor;
    }

    public function all(): array
    {
        $raw = get_option(self::OPTION, array());
        if (! is_array($raw)) {
            return array();
        }
        $out = array();
        foreach ($raw as $id => $row) {
            if (! is_array($row)) {
                continue;
            }
            $cipher = isset($row['token']) ? (string) $row['token'] : '';
            $plain = $this->decryptToken($cipher);
            $out[$id] = Deployment::fromArray(array_merge($row, array(
                'token' => $plain,
                'token_unreadable' => $cipher !== '' && $plain === '',
            )));
        }

        return $out;
    }

    public function find(string $id): ?Deployment
    {
        $all = $this->all();

        return isset($all[$id]) ? $all[$id] : null;
    }

    public function save(Deployment $deployment, bool $clearToken = false): void
    {
        $raw = get_option(self::OPTION, array());
        if (! is_array($raw)) {
            $raw = array();
        }
        $stored = isset($raw[$deployment->id()]) && is_array($raw[$deployment->id()])
            ? $raw[$deployment->id()]
            : array();
        $row = array_merge($deployment->toArray(), $this->tokenFields($stored, $deployment, $clearToken));
        $next = array_merge($raw, array($deployment->id() => $row));
        update_option(self::OPTION, $next, false);
    }

    public function delete(string $id): void
    {
        $raw = get_option(self::OPTION, array());
        if (! is_array($raw)) {
            return;
        }
        $next = array();
        foreach ($raw as $key => $row) {
            if ($key !== $id) {
                $next[$key] = $row;
            }
        }
        update_option(self::OPTION, $next, false);
    }

    private function tokenFields(array $stored, Deployment $deployment, bool $clearToken): array
    {
        if ($clearToken) {
            return self::blankTokenFields();
        }
        $storedCipher = isset($stored['token']) ? (string) $stored['token'] : '';
        $incoming = $deployment->token();
        if ($incoming !== '' && ! $this->isSameToken($storedCipher, $incoming)) {
            return array_merge(self::blankTokenFields(), array(
                'token' => $this->encryptor->encrypt($incoming),
                'token_last4' => Deployment::last4($incoming),
            ));
        }

        return array_merge(
            array(
                'token' => $storedCipher,
                'token_last4' => isset($stored['token_last4']) && $stored['token_last4'] !== ''
                    ? (string) $stored['token_last4']
                    : Deployment::last4($incoming),
                'token_unreadable' => false,
            ),
            $this->keptExpiry($stored, $deployment)
        );
    }

    private function keptExpiry(array $stored, Deployment $deployment): array
    {
        $current = $deployment->toArray();
        $kept = array();
        foreach (array('token_expires_at', 'token_checked_at', 'token_alerted') as $key) {
            $value = isset($current[$key]) ? (string) $current[$key] : '';
            if ($value === '' && isset($stored[$key])) {
                $value = (string) $stored[$key];
            }
            $kept = array_merge($kept, array($key => $value));
        }

        return $kept;
    }

    private function isSameToken(string $storedCipher, string $incoming): bool
    {
        return $storedCipher !== '' && $this->decryptToken($storedCipher) === $incoming;
    }

    private static function blankTokenFields(): array
    {
        return array(
            'token' => '',
            'token_last4' => '',
            'token_expires_at' => '',
            'token_checked_at' => '',
            'token_alerted' => '',
            'token_unreadable' => false,
        );
    }

    private function decryptToken(string $token): string
    {
        if ($token === '') {
            return '';
        }
        try {
            return $this->encryptor->decrypt($token);
        } catch (\Throwable $e) {
            error_log('Deployward: failed to decrypt a stored token: ' . $e->getMessage());
            return '';
        }
    }
}
