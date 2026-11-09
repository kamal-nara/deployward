<?php

namespace Deployward\Config;

interface DeploymentRepositoryInterface
{
    const TOKEN_NONE = 'none';
    const TOKEN_READABLE = 'readable';
    const TOKEN_UNREADABLE = 'unreadable';
    const TOKEN_MIGRATED_NOW = 'migrated-now';
    const TOKEN_NEEDS_MIGRATION = 'needs-migration';

    public function all(): array;

    public function find(string $id): ?Deployment;

    public function save(Deployment $deployment, bool $clearToken = false): void;

    public function recordTokenState(string $id, array $fields): void;

    public function tokenStatuses(): array;

    public function recordDeployedSha(string $id, string $sha): void;

    public function delete(string $id): void;
}
