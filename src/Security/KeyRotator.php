<?php

namespace Deployward\Security;

use Deployward\Config\DeploymentRepositoryInterface;
use Deployward\Support\Result;

final class KeyRotator
{
    /** @var KeyRing */
    private $ring;
    /** @var OwnKeyStoreInterface */
    private $store;
    /** @var DeploymentRepositoryInterface */
    private $repository;

    public function __construct(KeyRing $ring, OwnKeyStoreInterface $store, DeploymentRepositoryInterface $repository)
    {
        $this->ring = $ring;
        $this->store = $store;
        $this->repository = $repository;
    }

    public function rotate(bool $force): Result
    {
        if ($this->ring->usesConfigKey()) {
            return Result::fail(
                'Not rotating: ' . KeyRing::CONSTANT . " in wp-config.php is the active key, and rotate-key only "
                . "changes Deployward's own key, which is not in use while the constant is set."
            );
        }
        $unfinished = $this->finishInterruptedRotation();
        if ($unfinished !== null) {
            return $unfinished;
        }
        $unreadable = self::describe($this->repository->tokenStatuses(), DeploymentRepositoryInterface::TOKEN_UNREADABLE);
        if ($unreadable !== array() && ! $force) {
            return Result::fail(
                'Not rotating: the saved token for ' . implode(', ', $unreadable) . ' cannot be read. '
                . 'Paste it again first, or run with --force to rotate the key anyway.'
            );
        }

        return $this->switchKey(count($unreadable));
    }

    private function switchKey(int $unreadableCount): Result
    {
        try {
            $this->store->rotate(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        } catch (\RuntimeException $e) {
            return Result::fail('Not rotating: ' . $e->getMessage());
        }
        $after = $this->repository->tokenStatuses();
        $stuck = self::describe($after, DeploymentRepositoryInterface::TOKEN_NEEDS_MIGRATION);
        if ($stuck !== array()) {
            return Result::fail(
                'The new key is in use but the token for ' . implode(', ', $stuck) . ' could not be saved with it. '
                . 'The previous key is kept so nothing is lost; run rotate-key again to finish.'
            );
        }
        $this->store->finishRotation();
        $rotated = count(self::describe($after, DeploymentRepositoryInterface::TOKEN_MIGRATED_NOW));

        return Result::ok(
            array('rotated' => $rotated, 'unreadable' => $unreadableCount),
            self::summary($rotated, $unreadableCount)
        );
    }

    private function finishInterruptedRotation(): ?Result
    {
        if ($this->store->previous() === null) {
            return null;
        }
        $stuck = self::describe($this->repository->tokenStatuses(), DeploymentRepositoryInterface::TOKEN_NEEDS_MIGRATION);
        if ($stuck !== array()) {
            return Result::fail(
                'An earlier rotation has not finished: the token for ' . implode(', ', $stuck)
                . ' could not be saved with the new key yet. Fix the storage problem, then run rotate-key again.'
            );
        }
        $this->store->finishRotation();

        return null;
    }

    private static function describe(array $statuses, string $state): array
    {
        $labels = array();
        foreach ($statuses as $id => $status) {
            if ($status['state'] === $state) {
                $labels = array_merge($labels, array($id . ' (' . $status['repo'] . ')'));
            }
        }

        return $labels;
    }

    private static function summary(int $rotated, int $unreadable): string
    {
        $text = "Rotated Deployward's own key. Re-encrypted " . $rotated . ' saved token(s).';
        if ($unreadable > 0) {
            $text .= ' ' . $unreadable . ' unreadable token(s) were left as they were; paste them again.';
        }

        return $text;
    }
}
