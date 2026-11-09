<?php

namespace Deployward\Cli;

use Deployward\Config\DeploymentRepositoryInterface;
use Deployward\Security\KeyRing;
use Deployward\Security\KeyRotator;

final class KeyCommand
{
    /** @var KeyRing */
    private $ring;
    /** @var DeploymentRepositoryInterface */
    private $repository;
    /** @var KeyRotator */
    private $rotator;

    public function __construct(KeyRing $ring, DeploymentRepositoryInterface $repository, KeyRotator $rotator)
    {
        $this->ring = $ring;
        $this->repository = $repository;
        $this->rotator = $rotator;
    }

    /**
     * Shows which key protects the saved GitHub tokens and whether each token can be read.
     *
     * Tokens saved by an older version are re-encrypted with the current key when this runs.
     * Never prints a token, a key or a ciphertext.
     */
    public function status(array $args, array $assoc): void
    {
        \WP_CLI::line('Primary key: ' . $this->ring->source());
        $legacy = $this->ring->legacySources();
        \WP_CLI::line('Legacy keys: ' . ($legacy === array() ? 'none' : implode(', ', $legacy)));
        if ($this->ring->problem() !== '') {
            \WP_CLI::line('Problem: ' . $this->ring->problem());
        }
        foreach ($this->repository->tokenStatuses() as $id => $status) {
            \WP_CLI::line(sprintf('%s  %s  token=%s', $id, $status['repo'], $status['state']));
        }
    }

    /**
     * Replaces Deployward's own key and re-encrypts every saved token with the new one.
     *
     * Refuses while a saved token cannot be read, because rotating would not fix it.
     *
     * ## OPTIONS
     *
     * [--force]
     * : Rotate even when some saved tokens cannot be read. Those stay unreadable until pasted again.
     */
    public function rotate(array $args, array $assoc): void
    {
        $result = $this->rotator->rotate(isset($assoc['force']));
        if (! $result->isOk()) {
            \WP_CLI::error($result->message());
            return;
        }
        \WP_CLI::success($result->message());
    }
}
