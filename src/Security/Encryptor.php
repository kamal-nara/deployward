<?php

namespace Deployward\Security;

final class Encryptor
{
    /** @var string */
    private $key;

    public function __construct(string $key)
    {
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \InvalidArgumentException(
                'Encryptor key must be ' . SODIUM_CRYPTO_SECRETBOX_KEYBYTES . ' bytes.'
            );
        }
        $this->key = $key;
    }

    public static function fromSalts(): self
    {
        $material = (defined('AUTH_KEY') ? AUTH_KEY : '') . (defined('AUTH_SALT') ? AUTH_SALT : '');
        if ($material === '') {
            throw new \RuntimeException('WordPress salts are not defined.');
        }
        $key = sodium_crypto_generichash($material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

        return new self($key);
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return base64_encode($nonce . $cipher);
    }

    public function decrypt(string $ciphertext): string
    {
        $decoded = base64_decode($ciphertext, true);
        if ($decoded === false || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Invalid ciphertext.');
        }
        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);
        if ($plain === false) {
            throw new \RuntimeException('Decryption failed.');
        }

        return $plain;
    }
}
