<?php

namespace Deployward\Tests\Unit\Security;

use Deployward\Security\Encryptor;
use Deployward\Security\KeyRing;
use Deployward\Tests\Support\InMemoryKeyStore;
use PHPUnit\Framework\TestCase;

final class EncryptorTest extends TestCase
{
    const SALT_MATERIAL = 'auth-key-material-and-auth-salt-material';

    private function encryptor(?InMemoryKeyStore $store = null, string $salts = ''): Encryptor
    {
        $keys = $store !== null ? $store : new InMemoryKeyStore(InMemoryKeyStore::randomKey());

        return new Encryptor(KeyRing::fromMaterial(null, $salts, $keys));
    }

    private static function saltsKey(): string
    {
        return sodium_crypto_generichash(self::SALT_MATERIAL, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    private static function sealWith(string $key, string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $key));
    }

    private static function opens(string $key, string $ciphertext): bool
    {
        $decoded = base64_decode($ciphertext, true);
        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return sodium_crypto_secretbox_open(substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key) !== false;
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

    public function test_a_bit_flipped_ciphertext_is_rejected_by_the_mac(): void
    {
        $enc = $this->encryptor();
        $raw = base64_decode($enc->encrypt('ghp_secret_token'), true);
        $flipped = substr($raw, 0, -1) . chr(ord(substr($raw, -1)) ^ 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Decryption failed.');

        $enc->decrypt(base64_encode($flipped));
    }

    public function test_encrypt_always_uses_the_primary_key(): void
    {
        $primary = InMemoryKeyStore::randomKey();
        $enc = $this->encryptor(new InMemoryKeyStore($primary, InMemoryKeyStore::randomKey()), self::SALT_MATERIAL);

        $cipher = $enc->encrypt('ghp_secret_token');

        $this->assertTrue(self::opens($primary, $cipher));
    }

    public function test_encrypt_never_uses_the_wordpress_salts(): void
    {
        $enc = $this->encryptor(new InMemoryKeyStore(InMemoryKeyStore::randomKey()), self::SALT_MATERIAL);

        $cipher = $enc->encrypt('ghp_secret_token');

        $this->assertFalse(self::opens(self::saltsKey(), $cipher));
    }

    public function test_decrypt_reads_a_token_that_an_older_version_locked_with_the_salts(): void
    {
        $enc = $this->encryptor(new InMemoryKeyStore(InMemoryKeyStore::randomKey()), self::SALT_MATERIAL);
        $oldCipher = self::sealWith(self::saltsKey(), 'ghp_from_0_5_0');

        $this->assertSame('ghp_from_0_5_0', $enc->decrypt($oldCipher));
        $this->assertFalse($enc->usesPrimaryKey($oldCipher));
    }

    public function test_decrypt_reads_a_token_locked_with_the_previous_own_key(): void
    {
        $previous = InMemoryKeyStore::randomKey();
        $enc = $this->encryptor(new InMemoryKeyStore(InMemoryKeyStore::randomKey(), $previous));

        $this->assertSame('ghp_old', $enc->decrypt(self::sealWith($previous, 'ghp_old')));
    }

    public function test_a_token_locked_with_the_primary_key_reports_it(): void
    {
        $enc = $this->encryptor(new InMemoryKeyStore(InMemoryKeyStore::randomKey()), self::SALT_MATERIAL);

        $this->assertTrue($enc->usesPrimaryKey($enc->encrypt('ghp_new')));
    }

    public function test_the_primary_key_wins_when_two_keys_could_be_tried(): void
    {
        $primary = InMemoryKeyStore::randomKey();
        $enc = $this->encryptor(new InMemoryKeyStore($primary, $primary));

        $this->assertSame('ghp_x', $enc->decrypt(self::sealWith($primary, 'ghp_x')));
    }

    public function test_decrypting_never_creates_the_own_key(): void
    {
        $store = new InMemoryKeyStore();
        $enc = $this->encryptor($store, self::SALT_MATERIAL);

        $this->assertSame('ghp_old', $enc->decrypt(self::sealWith(self::saltsKey(), 'ghp_old')));
        $this->assertFalse($enc->usesPrimaryKey(self::sealWith(self::saltsKey(), 'ghp_old')));
        $this->assertSame(0, $store->creations);
    }

    public function test_a_damaged_own_key_still_lets_a_legacy_token_be_read_but_not_written(): void
    {
        $store = new InMemoryKeyStore();
        $store->isBroken = true;
        $enc = $this->encryptor($store, self::SALT_MATERIAL);

        $this->assertSame('ghp_old', $enc->decrypt(self::sealWith(self::saltsKey(), 'ghp_old')));
        $this->expectException(\RuntimeException::class);

        $enc->encrypt('ghp_new');
    }

    public function test_a_token_no_key_can_open_is_unreadable(): void
    {
        $enc = $this->encryptor(new InMemoryKeyStore(InMemoryKeyStore::randomKey()), self::SALT_MATERIAL);
        $foreign = self::sealWith(InMemoryKeyStore::randomKey(), 'ghp_elsewhere');

        $this->assertFalse($enc->usesPrimaryKey($foreign));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Decryption failed.');

        $enc->decrypt($foreign);
    }
}
