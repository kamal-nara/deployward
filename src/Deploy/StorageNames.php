<?php

namespace Deployward\Deploy;

use Deployward\Support\WriteOnceOption;

final class StorageNames
{
    const OPTION = 'deployward_dir_suffix';
    const FALLBACK = 'shared';
    const SUFFIX_PATTERN = '/^[A-Za-z0-9]{8,64}$/';

    /** @var WriteOnceOption */
    private $stored;

    public function __construct($wpdb)
    {
        $this->stored = new WriteOnceOption($wpdb, self::OPTION);
    }

    public function suffix(): string
    {
        try {
            $suffix = $this->stored->read();
            if ($suffix === '') {
                $suffix = $this->stored->insertIfAbsent(bin2hex(random_bytes(8)));
            }
        } catch (\Throwable $e) {
            error_log('Deployward: could not store the directory suffix, using a fixed name: ' . $e->getMessage());

            return self::FALLBACK;
        }
        if (preg_match(self::SUFFIX_PATTERN, $suffix) === 1) {
            return $suffix;
        }
        error_log('Deployward: the stored directory suffix is not valid, using a fixed name.');

        return self::FALLBACK;
    }
}
