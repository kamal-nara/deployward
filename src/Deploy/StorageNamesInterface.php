<?php

namespace Deployward\Deploy;

interface StorageNamesInterface
{
    /** @throws \RuntimeException when the stored suffix cannot be read or created */
    public function suffix(): string;
}
