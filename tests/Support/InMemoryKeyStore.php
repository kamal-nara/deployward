<?php

namespace Deployward\Tests\Support;

use Deployward\Security\OwnKeyStoreInterface;

final class InMemoryKeyStore implements OwnKeyStoreInterface
{
    /** @var ?string */
    public $currentKey;
    /** @var ?string */
    public $previousKey;
    /** @var int */
    public $creations = 0;
    /** @var bool */
    public $isBroken = false;

    public function __construct(?string $currentKey = null, ?string $previousKey = null)
    {
        $this->currentKey = $currentKey;
        $this->previousKey = $previousKey;
    }

    public static function randomKey(): string
    {
        return random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function current(): ?string
    {
        return $this->currentKey;
    }

    public function previous(): ?string
    {
        return $this->previousKey;
    }

    public function ensure(): string
    {
        if ($this->isBroken) {
            throw new \RuntimeException('own key unavailable');
        }
        if ($this->currentKey === null) {
            $this->currentKey = self::randomKey();
            $this->creations++;
        }

        return $this->currentKey;
    }

    public function rotate(string $newKey): void
    {
        $this->previousKey = $this->currentKey;
        $this->currentKey = $newKey;
    }

    public function finishRotation(): void
    {
        $this->previousKey = null;
    }
}
