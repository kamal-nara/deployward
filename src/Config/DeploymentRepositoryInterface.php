<?php

namespace Deployward\Config;

interface DeploymentRepositoryInterface
{
    public function all(): array;

    public function find(string $id): ?Deployment;

    public function save(Deployment $deployment, bool $clearToken = false): void;

    public function recordTokenState(string $id, array $fields): void;

    public function recordDeployedSha(string $id, string $sha): void;

    public function delete(string $id): void;
}
