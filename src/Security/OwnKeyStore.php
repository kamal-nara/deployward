<?php

namespace Deployward\Security;

final class OwnKeyStore implements OwnKeyStoreInterface
{
    const OPTION = 'deployward_encryption_key';
    const PREVIOUS_OPTION = 'deployward_encryption_key_previous';

    /** @var object */
    private $wpdb;

    public function __construct($wpdb)
    {
        $this->wpdb = $wpdb;
    }

    public function current(): ?string
    {
        return self::decode(get_option(self::OPTION, ''));
    }

    public function previous(): ?string
    {
        return self::decode(get_option(self::PREVIOUS_OPTION, ''));
    }

    public function ensure(): string
    {
        $existing = $this->current();
        if ($existing !== null) {
            return $existing;
        }
        if (get_option(self::OPTION, '') !== '') {
            throw new \RuntimeException(
                "Deployward's own encryption key is stored but damaged, and it is never replaced automatically."
            );
        }
        $this->insertIfAbsent(base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
        $stored = $this->current();
        if ($stored === null) {
            throw new \RuntimeException("Deployward could not store its own encryption key.");
        }

        return $stored;
    }

    /**
     * add_option() would overwrite on a race or after an empty read, because it skips its
     * existence check when the option is cached as absent. INSERT IGNORE cannot overwrite.
     */
    private function insertIfAbsent(string $encodedKey): void
    {
        $sql = $this->wpdb->prepare(
            "INSERT IGNORE INTO {$this->wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
            self::OPTION,
            $encodedKey,
            'no'
        );
        $result = $this->wpdb->query($sql);
        wp_cache_delete(self::OPTION, 'options');
        wp_cache_delete('notoptions', 'options');
        if ($result === false || $this->wpdb->last_error !== '') {
            throw new \RuntimeException('Deployward could not store its own encryption key.');
        }
    }

    public function rotate(string $newKey): void
    {
        if (strlen($newKey) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \InvalidArgumentException('Key must be ' . SODIUM_CRYPTO_SECRETBOX_KEYBYTES . ' bytes.');
        }
        $old = get_option(self::OPTION, '');
        if ($old !== '' && ! update_option(self::PREVIOUS_OPTION, $old, false)) {
            throw new \RuntimeException('Deployward could not keep the previous key, so nothing was changed.');
        }
        if (! update_option(self::OPTION, base64_encode($newKey), false)) {
            throw new \RuntimeException('Deployward could not store the new key.');
        }
    }

    public function finishRotation(): void
    {
        delete_option(self::PREVIOUS_OPTION);
    }

    private static function decode($stored): ?string
    {
        if (! is_string($stored) || $stored === '') {
            return null;
        }
        $raw = base64_decode($stored, true);

        return $raw !== false && strlen($raw) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES ? $raw : null;
    }
}
