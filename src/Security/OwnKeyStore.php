<?php

namespace Deployward\Security;

use Deployward\Support\WriteOnceOption;

final class OwnKeyStore implements OwnKeyStoreInterface
{
    const OPTION = 'deployward_encryption_key';
    const PREVIOUS_OPTION = 'deployward_encryption_key_previous';

    /** @var WriteOnceOption */
    private $stored;

    public function __construct($wpdb)
    {
        $this->stored = new WriteOnceOption($wpdb, self::OPTION);
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
        $this->stored->insertIfAbsent(base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
        $stored = $this->current();
        if ($stored === null) {
            throw new \RuntimeException("Deployward could not store its own encryption key.");
        }

        return $stored;
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
