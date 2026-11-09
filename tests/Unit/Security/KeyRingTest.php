<?php

namespace Deployward\Tests\Unit\Security;

use Deployward\Security\KeyRing;
use Deployward\Tests\Support\InMemoryKeyStore;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class KeyRingTest extends TestCase
{
    const CONFIG_KEY = 'wp-config-key-0123456789-abcdefghij-XYZ';
    const SALT_MATERIAL = 'auth-key-material-and-auth-salt-material';

    private static function derive(string $material): string
    {
        return sodium_crypto_generichash($material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function test_the_primary_key_comes_from_the_constant_when_it_is_long_enough(): void
    {
        $store = new InMemoryKeyStore();

        $ring = KeyRing::fromMaterial(self::CONFIG_KEY, self::SALT_MATERIAL, $store);

        $this->assertSame(self::derive(self::CONFIG_KEY), $ring->primaryKey());
        $this->assertSame(KeyRing::SOURCE_CONFIG, $ring->source());
        $this->assertTrue($ring->usesConfigKey());
        $this->assertSame('', $ring->problem());
        $this->assertSame(0, $store->creations);
    }

    public function test_a_constant_of_exactly_32_characters_is_accepted(): void
    {
        $ring = KeyRing::fromMaterial(str_repeat('k', 32), '', new InMemoryKeyStore());

        $this->assertTrue($ring->usesConfigKey());
    }

    public function test_without_the_constant_the_own_key_is_the_primary(): void
    {
        $own = InMemoryKeyStore::randomKey();

        $ring = KeyRing::fromMaterial(null, self::SALT_MATERIAL, new InMemoryKeyStore($own));

        $this->assertSame($own, $ring->primaryKey());
        $this->assertSame(KeyRing::SOURCE_OWN, $ring->source());
        $this->assertFalse($ring->usesConfigKey());
        $this->assertSame('', $ring->problem());
    }

    public function test_the_own_key_is_generated_once_on_first_need_and_reused(): void
    {
        $store = new InMemoryKeyStore();
        $ring = KeyRing::fromMaterial(null, self::SALT_MATERIAL, $store);
        $this->assertSame(0, $store->creations);

        $first = $ring->primaryKey();
        $second = KeyRing::fromMaterial(null, self::SALT_MATERIAL, $store)->primaryKey();

        $this->assertSame(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, strlen($first));
        $this->assertSame($first, $second);
        $this->assertSame(1, $store->creations);
    }

    public function test_reading_never_creates_the_own_key(): void
    {
        $store = new InMemoryKeyStore();
        $ring = KeyRing::fromMaterial(null, self::SALT_MATERIAL, $store);

        $this->assertNull($ring->primaryKeyIfAvailable());
        $this->assertSame(0, $store->creations);
    }

    public function test_the_available_primary_is_the_existing_own_key_or_the_constant(): void
    {
        $own = InMemoryKeyStore::randomKey();

        $byOwn = KeyRing::fromMaterial(null, '', new InMemoryKeyStore($own));
        $byConfig = KeyRing::fromMaterial(self::CONFIG_KEY, '', new InMemoryKeyStore());

        $this->assertSame($own, $byOwn->primaryKeyIfAvailable());
        $this->assertSame(self::derive(self::CONFIG_KEY), $byConfig->primaryKeyIfAvailable());
    }

    public function test_the_salts_key_is_never_the_primary(): void
    {
        $ring = KeyRing::fromMaterial(null, self::SALT_MATERIAL, new InMemoryKeyStore());

        $this->assertNotSame(self::derive(self::SALT_MATERIAL), $ring->primaryKey());
    }

    public function test_the_salts_key_is_the_last_read_only_legacy_key(): void
    {
        $previous = InMemoryKeyStore::randomKey();
        $ring = KeyRing::fromMaterial(null, self::SALT_MATERIAL, new InMemoryKeyStore(InMemoryKeyStore::randomKey(), $previous));

        $this->assertSame(array($previous, self::derive(self::SALT_MATERIAL)), $ring->legacyKeys());
        $this->assertSame(array(KeyRing::LEGACY_OWN_PREVIOUS, KeyRing::LEGACY_SALTS), $ring->legacySources());
    }

    public function test_an_existing_own_key_stays_readable_when_the_constant_becomes_primary(): void
    {
        $own = InMemoryKeyStore::randomKey();

        $ring = KeyRing::fromMaterial(self::CONFIG_KEY, self::SALT_MATERIAL, new InMemoryKeyStore($own));

        $this->assertSame(array($own, self::derive(self::SALT_MATERIAL)), $ring->legacyKeys());
        $this->assertSame(array(KeyRing::LEGACY_OWN, KeyRing::LEGACY_SALTS), $ring->legacySources());
    }

    public function test_listing_the_legacy_keys_never_creates_the_own_key(): void
    {
        $store = new InMemoryKeyStore();

        KeyRing::fromMaterial(null, self::SALT_MATERIAL, $store)->legacyKeys();

        $this->assertSame(0, $store->creations);
    }

    public function test_there_is_no_legacy_salts_key_when_the_salts_are_missing(): void
    {
        $ring = KeyRing::fromMaterial(null, '', new InMemoryKeyStore(InMemoryKeyStore::randomKey()));

        $this->assertSame(array(), $ring->legacyKeys());
    }

    public function test_a_constant_shorter_than_32_characters_is_ignored_and_reported(): void
    {
        $own = InMemoryKeyStore::randomKey();

        $ring = KeyRing::fromMaterial(str_repeat('k', 31), self::SALT_MATERIAL, new InMemoryKeyStore($own));

        $this->assertSame($own, $ring->primaryKey());
        $this->assertFalse($ring->usesConfigKey());
        $this->assertStringContainsString('DEPLOYWARD_ENCRYPTION_KEY', $ring->problem());
        $this->assertStringContainsString('32', $ring->problem());
    }

    public function test_a_constant_that_is_not_a_string_is_ignored_and_reported(): void
    {
        $ring = KeyRing::fromMaterial(12345678901234567890123456789012, self::SALT_MATERIAL, new InMemoryKeyStore());

        $this->assertFalse($ring->usesConfigKey());
        $this->assertNotSame('', $ring->problem());
    }

    public function test_the_problem_text_never_contains_the_constant_value(): void
    {
        $short = 'short-secret-value';

        $ring = KeyRing::fromMaterial($short, self::SALT_MATERIAL, new InMemoryKeyStore());

        $this->assertStringNotContainsString($short, $ring->problem());
    }

    public function test_the_summary_names_the_active_key_source(): void
    {
        $own = KeyRing::fromMaterial(null, '', new InMemoryKeyStore());
        $config = KeyRing::fromMaterial(self::CONFIG_KEY, '', new InMemoryKeyStore());

        $this->assertSame(
            "Deployward's own key (only changes when an admin runs wp deployward rotate-key)",
            $own->summary()
        );
        $this->assertSame('DEPLOYWARD_ENCRYPTION_KEY in wp-config.php', $config->summary());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_from_constants_reads_the_defined_constants(): void
    {
        define('AUTH_KEY', 'auth-key-material');
        define('AUTH_SALT', '-and-auth-salt-material');
        define('DEPLOYWARD_ENCRYPTION_KEY', self::CONFIG_KEY);

        $ring = KeyRing::fromConstants(new InMemoryKeyStore());

        $this->assertSame(self::derive(self::CONFIG_KEY), $ring->primaryKey());
        $this->assertSame(array(self::derive('auth-key-material-and-auth-salt-material')), $ring->legacyKeys());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_from_constants_uses_the_own_key_when_the_constant_is_absent(): void
    {
        define('AUTH_KEY', 'auth-key-material');
        define('AUTH_SALT', '-and-auth-salt-material');
        $own = InMemoryKeyStore::randomKey();

        $ring = KeyRing::fromConstants(new InMemoryKeyStore($own));

        $this->assertSame($own, $ring->primaryKey());
        $this->assertFalse($ring->usesConfigKey());
    }
}
