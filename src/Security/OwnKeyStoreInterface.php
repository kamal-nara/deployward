<?php

namespace Deployward\Security;

interface OwnKeyStoreInterface
{
    public function current(): ?string;

    public function previous(): ?string;

    public function ensure(): string;

    public function rotate(string $newKey): void;

    public function finishRotation(): void;
}
