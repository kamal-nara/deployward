<?php

namespace Deployward\GitHub;

use Deployward\Support\Result;

interface GitHubClientInterface
{
    public function resolveSha(string $repo, string $ref, ?string $token): Result;

    public function downloadZipball(string $repo, string $ref, ?string $token, string $destFile): Result;

    public function listBranches(string $repo, ?string $token): Result;

    public function lastTokenExpiry(): string;
}
