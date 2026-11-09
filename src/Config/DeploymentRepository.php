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
            $read = $this->readToken((string) $id, self::cipherOf($row));
            $out[$id] = Deployment::fromArray(array_merge($row, array(
                'token' => $read['plain'],
                'token_unreadable' => $read['state'] === self::TOKEN_UNREADABLE,
            )));
        }

        return $out;
    }

    public function tokenStatuses(): array
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
            $out[$id] = array(
                'repo' => isset($row['repo']) ? (string) $row['repo'] : '',
                'state' => $this->readToken((string) $id, self::cipherOf($row))['state'],
            );
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

    public function recordTokenState(string $id, array $fields): void
    {
        $changes = array();
        foreach (self::recordableTokenFields() as $key) {
            if (array_key_exists($key, $fields)) {
                $changes = array_merge($changes, array($key => (string) $fields[$key]));
            }
        }
        $this->patchRow($id, $changes);
    }

    public function recordDeployedSha(string $id, string $sha): void
    {
        $this->patchRow($id, array('last_deployed_sha' => $sha));
    }

    private static function recordableTokenFields(): array
    {
        return array_values(array_diff(Deployment::TOKEN_FIELDS, array('token_last4')));
    }

    private function patchRow(string $id, array $changes, array $expected = array()): bool
    {
        if ($changes === array()) {
            return true;
        }
        $raw = get_option(self::OPTION, array());
        if (! is_array($raw) || ! isset($raw[$id]) || ! is_array($raw[$id])) {
            return false;
        }
        foreach ($expected as $key => $value) {
            if (! isset($raw[$id][$key]) || (string) $raw[$id][$key] !== $value) {
                return false;
            }
        }
        $row = array_merge($raw[$id], $changes);
        if ($row === $raw[$id]) {
            return true;
        }

        return (bool) update_option(self::OPTION, array_merge($raw, array($id => $row)), false);
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
                'token_unreadable_at' => $this->keptUnreadableAt($stored, $storedCipher),
            ),
            $this->keptExpiry($stored, $deployment)
        );
    }

    private function keptUnreadableAt(array $stored, string $storedCipher): string
    {
        $isUnreadable = $storedCipher !== '' && $this->decryptToken($storedCipher) === '';

        return $isUnreadable && isset($stored['token_unreadable_at']) ? (string) $stored['token_unreadable_at'] : '';
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
            'token_unreadable_at' => '',
            'token_unreadable' => false,
        );
    }

    private static function cipherOf(array $row): string
    {
        return isset($row['token']) ? (string) $row['token'] : '';
    }

    private function readToken(string $id, string $cipher): array
    {
        if ($cipher === '') {
            return array('plain' => '', 'state' => self::TOKEN_NONE);
        }
        try {
            $plain = $this->encryptor->decrypt($cipher);
            $isCurrent = $this->encryptor->usesPrimaryKey($cipher);
        } catch (\Throwable $e) {
            error_log('Deployward: failed to decrypt a stored token: ' . $e->getMessage());
            return array('plain' => '', 'state' => self::TOKEN_UNREADABLE);
        }

        return array(
            'plain' => $plain,
            'state' => $isCurrent ? self::TOKEN_READABLE : $this->migrateToken($id, $cipher, $plain),
        );
    }

    private function migrateToken(string $id, string $oldCipher, string $plain): string
    {
        try {
            $fresh = $this->encryptor->encrypt($plain);
            $isSound = $this->encryptor->usesPrimaryKey($fresh) && $this->encryptor->decrypt($fresh) === $plain;
        } catch (\Throwable $e) {
            error_log('Deployward: could not re-encrypt a stored token, left as it was: ' . $e->getMessage());
            return self::TOKEN_NEEDS_MIGRATION;
        }
        $isSaved = $isSound && $this->patchRow($id, array('token' => $fresh), array('token' => $oldCipher));

        return $isSaved ? self::TOKEN_MIGRATED_NOW : self::TOKEN_NEEDS_MIGRATION;
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
