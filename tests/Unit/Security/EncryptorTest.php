<?php

namespace Deployward\Tests\Unit\Security;

use Deployward\Security\Encryptor;
use PHPUnit\Framework\TestCase;

final class EncryptorTest extends TestCase
{
    private function encryptor(): Encryptor
    {
        return new Encryptor(str_repeat('k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    public function test_round_trips_a_secret(): void
    {
        $enc = $this->encryptor();
        $cipher = $enc->encrypt('ghp_secret_token');

        $this->assertNotSame('ghp_secret_token', $cipher);
        $this->assertSame('ghp_secret_token', $enc->decrypt($cipher));
    }

    public function test_two_encryptions_differ_due_to_nonce(): void
    {
        $enc = $this->encryptor();

        $this->assertNotSame($enc->encrypt('same'), $enc->encrypt('same'));
    }

    public function test_tampered_ciphertext_throws(): void
    {
        $enc = $this->encryptor();
        $this->expectException(\RuntimeException::class);

        $enc->decrypt(base64_encode('not-a-valid-box'));
    }

    public function test_wrong_key_size_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Encryptor('too-short');
    }
}
