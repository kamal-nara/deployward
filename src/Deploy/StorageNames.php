<?php

namespace Deployward\Deploy;

use Deployward\Support\WriteOnceOption;

final class StorageNames implements StorageNamesInterface
{
    const OPTION = 'deployward_dir_suffix';
    const SUFFIX_PATTERN = '/^[A-Za-z0-9]{8,64}$/';

    /** @var WriteOnceOption */
    private $stored;
    /** @var ?string */
    private $resolved = null;

    public function __construct($wpdb)
    {
        $this->stored = new WriteOnceOption($wpdb, self::OPTION);
    }

    public function suffix(): string
    {
        if ($this->resolved === null) {
            $this->resolved = $this->resolve();
        }

        return $this->resolved;
    }

    private function resolve(): string
    {
        $suffix = $this->stored->read();
        if ($suffix === '') {
            $suffix = $this->stored->insertIfAbsent(bin2hex(random_bytes(8)));
        }
        if (preg_match(self::SUFFIX_PATTERN, $suffix) !== 1) {
            throw new \RuntimeException(
                "Deployward's stored folder suffix is not valid, and it is never replaced automatically."
            );
        }

        return $suffix;
    }
}
