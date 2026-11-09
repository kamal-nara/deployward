<?php

namespace Deployward\Security;

final class Encryptor
{
    /** @var KeyRing */
    private $keys;

    public function __construct(KeyRing $keys)
    {
        $this->keys = $keys;
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->keys->primaryKey());

        return base64_encode($nonce . $cipher);
    }

    public function decrypt(string $ciphertext): string
    {
        $plain = $this->open($ciphertext, array_merge($this->primaryCandidates(), $this->keys->legacyKeys()));
        if ($plain === null) {
            throw new \RuntimeException('Decryption failed.');
        }

        return $plain;
    }

    public function usesPrimaryKey(string $ciphertext): bool
    {
        return $this->open($ciphertext, $this->primaryCandidates()) !== null;
    }

    private function primaryCandidates(): array
    {
        $primary = $this->keys->primaryKeyIfAvailable();

        return $primary !== null ? array($primary) : array();
    }

    private function open(string $ciphertext, array $candidateKeys): ?string
    {
        $decoded = base64_decode($ciphertext, true);
        if ($decoded === false || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Invalid ciphertext.');
        }
        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        foreach ($candidateKeys as $key) {
            $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
            if ($plain !== false) {
                return $plain;
            }
        }

        return null;
    }
}
