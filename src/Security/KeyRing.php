<?php

namespace Deployward\Security;

final class KeyRing
{
    const CONSTANT = 'DEPLOYWARD_ENCRYPTION_KEY';
    const MIN_CONFIGURED_LENGTH = 32;
    const SOURCE_CONFIG = 'wp-config';
    const SOURCE_OWN = 'own';
    const LEGACY_OWN_PREVIOUS = 'own-previous';
    const LEGACY_OWN = 'own';
    const LEGACY_SALTS = 'salts';
    const SAVE_FAILURE_MESSAGE = 'Deployward could not save the token because it cannot lock it with its encryption key '
        . '(the key is damaged or cannot be stored). Nothing was changed. Check the PHP error log for details.';

    /** @var ?string */
    private $configuredKey;
    /** @var ?string */
    private $saltsKey;
    /** @var OwnKeyStoreInterface */
    private $own;
    /** @var string */
    private $problem;

    private function __construct(?string $configuredKey, ?string $saltsKey, OwnKeyStoreInterface $own, string $problem)
    {
        $this->configuredKey = $configuredKey;
        $this->saltsKey = $saltsKey;
        $this->own = $own;
        $this->problem = $problem;
    }

    public static function fromConstants(OwnKeyStoreInterface $own): self
    {
        $configured = defined(self::CONSTANT) ? constant(self::CONSTANT) : null;
        $material = (defined('AUTH_KEY') ? (string) AUTH_KEY : '') . (defined('AUTH_SALT') ? (string) AUTH_SALT : '');

        return self::fromMaterial($configured, $material, $own);
    }

    public static function fromMaterial($configured, string $saltMaterial, OwnKeyStoreInterface $own): self
    {
        $isUsable = is_string($configured) && strlen($configured) >= self::MIN_CONFIGURED_LENGTH;
        $problem = $configured !== null && ! $isUsable
            ? self::CONSTANT . ' in wp-config.php must be a string of at least '
                . self::MIN_CONFIGURED_LENGTH . ' characters, so it is ignored.'
            : '';

        return new self(
            $isUsable ? self::derive($configured) : null,
            $saltMaterial !== '' ? self::derive($saltMaterial) : null,
            $own,
            $problem
        );
    }

    public function primaryKey(): string
    {
        return $this->configuredKey !== null ? $this->configuredKey : $this->own->ensure();
    }

    public function primaryKeyIfAvailable(): ?string
    {
        return $this->configuredKey !== null ? $this->configuredKey : $this->own->current();
    }

    public function legacyKeys(): array
    {
        return array_map(function (array $entry) {
            return $entry['key'];
        }, $this->legacyEntries());
    }

    public function legacySources(): array
    {
        return array_map(function (array $entry) {
            return $entry['source'];
        }, $this->legacyEntries());
    }

    public function source(): string
    {
        return $this->configuredKey !== null ? self::SOURCE_CONFIG : self::SOURCE_OWN;
    }

    public function usesConfigKey(): bool
    {
        return $this->configuredKey !== null;
    }

    public function problem(): string
    {
        return $this->problem;
    }

    public function summary(): string
    {
        return $this->usesConfigKey()
            ? self::CONSTANT . ' in wp-config.php'
            : "Deployward's own key (only changes when an admin runs wp deployward rotate-key)";
    }

    private function legacyEntries(): array
    {
        $entries = array();
        $previous = $this->own->previous();
        if ($previous !== null) {
            $entries = array_merge($entries, array(array('source' => self::LEGACY_OWN_PREVIOUS, 'key' => $previous)));
        }
        $current = $this->configuredKey !== null ? $this->own->current() : null;
        if ($current !== null) {
            $entries = array_merge($entries, array(array('source' => self::LEGACY_OWN, 'key' => $current)));
        }
        if ($this->saltsKey !== null) {
            $entries = array_merge($entries, array(array('source' => self::LEGACY_SALTS, 'key' => $this->saltsKey)));
        }

        return $entries;
    }

    private static function derive(string $material): string
    {
        return sodium_crypto_generichash($material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
