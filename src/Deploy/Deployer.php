<?php

namespace Deployward\Deploy;

use Deployward\Config\Deployment;
use Deployward\Config\DeploymentRepositoryInterface;
use Deployward\GitHub\GitHubClientInterface;
use Deployward\Log\DeployLogInterface;
use Deployward\Notify\NotifierInterface;
use Deployward\Support\Result;

final class Deployer implements DeployerInterface
{
    /** @var GitHubClientInterface */
    private $github;
    /** @var ExtractorInterface */
    private $extractor;
    /** @var PayloadValidatorInterface */
    private $validator;
    /** @var BackupManagerInterface */
    private $backups;
    /** @var DirectoryMoverInterface */
    private $mover;
    /** @var HealthCheckerInterface */
    private $health;
    /** @var MaintenanceModeInterface */
    private $maintenance;
    /** @var DeployLogInterface */
    private $log;
    /** @var DeploymentRepositoryInterface */
    private $repository;
    /** @var NotifierInterface */
    private $notifier;
    /** @var array */
    private $targetRoots;
    /** @var string */
    private $healthUrl;
    /** @var string */
    private $tempDir;
    /** @var string */
    private $selfSlug;
    /** @var int */
    private $keepBackups;

    public function __construct(
        GitHubClientInterface $github,
        ExtractorInterface $extractor,
        PayloadValidatorInterface $validator,
        BackupManagerInterface $backups,
        DirectoryMoverInterface $mover,
        HealthCheckerInterface $health,
        MaintenanceModeInterface $maintenance,
        DeployLogInterface $log,
        DeploymentRepositoryInterface $repository,
        NotifierInterface $notifier,
        array $targetRoots,
        string $healthUrl,
        string $tempDir,
        string $selfSlug = 'deployward',
        int $keepBackups = 3
    ) {
        $this->github = $github;
        $this->extractor = $extractor;
        $this->validator = $validator;
        $this->backups = $backups;
        $this->mover = $mover;
        $this->health = $health;
        $this->maintenance = $maintenance;
        $this->log = $log;
        $this->repository = $repository;
        $this->notifier = $notifier;
        $this->targetRoots = $targetRoots;
        $this->healthUrl = $healthUrl;
        $this->tempDir = rtrim($tempDir, '/');
        $this->selfSlug = $selfSlug;
        $this->keepBackups = $keepBackups;
    }

    public function deploy(Deployment $deployment, string $trigger, bool $force = false): Result
    {
        $guard = $this->guardSelf($deployment);
        if ($guard !== null) {
            return $this->finish($deployment, $trigger, $guard, '');
        }

        $shaResult = $this->github->resolveSha(
            $deployment->repo(),
            $deployment->branch(),
            $this->tokenOrNull($deployment)
        );
        if (! $shaResult->isOk()) {
            return $this->finish($deployment, $trigger, $shaResult, '');
        }
        $sha = (string) $shaResult->data();

        if (! $force && $sha === $deployment->lastDeployedSha()) {
            return $this->finish($deployment, $trigger, Result::skip('Already at latest commit'), $sha);
        }

        $payload = $this->preparePayload($deployment, $sha);
        if (! $payload->isOk()) {
            return $this->finish($deployment, $trigger, $payload, $sha);
        }
        $newDir = (string) $payload->data();

        $swap = $this->swap($deployment, $newDir, $sha);
        $this->extractor->cleanup($newDir);
        if ($swap->isOk()) {
            $this->backups->prune($deployment->targetSlug(), $this->keepBackups);
            $this->repository->save($deployment->withLastDeployedSha($sha));
        }

        return $this->finish($deployment, $trigger, $swap, $sha);
    }

    public function rollback(Deployment $deployment, string $trigger = 'manual-rollback'): Result
    {
        $root = isset($this->targetRoots[$deployment->targetType()])
            ? $this->targetRoots[$deployment->targetType()] : '';
        if ($root === '') {
            return $this->finish($deployment, $trigger, Result::fail('Unknown target type'), '');
        }
        $targetDir = rtrim($root, '/') . '/' . $deployment->targetSlug();

        $this->maintenance->enable();
        try {
            $restore = $this->backups->restoreLatest($deployment->targetSlug(), $targetDir);
        } finally {
            $this->maintenance->disable();
        }

        return $this->finish($deployment, $trigger, $restore, '');
    }

    private function preparePayload(Deployment $deployment, string $sha): Result
    {
        $zip = $this->tempDir . '/deployward-' . substr($sha, 0, 8) . '.zip';
        $download = $this->github->downloadZipball(
            $deployment->repo(),
            $sha,
            $this->tokenOrNull($deployment),
            $zip
        );
        if (! $download->isOk()) {
            return $download;
        }

        $extract = $this->extractor->extract($zip);
        @unlink($zip);
        if (! $extract->isOk()) {
            return $extract;
        }
        $newDir = (string) $extract->data();

        $valid = $this->validator->validate($newDir, $deployment->targetType(), $deployment->targetSlug());
        if (! $valid->isOk()) {
            return $valid;
        }

        return Result::ok($newDir);
    }

    private function swap(Deployment $deployment, string $newDir, string $sha): Result
    {
        $root = $this->targetRoots[$deployment->targetType()];
        $targetDir = rtrim($root, '/') . '/' . $deployment->targetSlug();

        $this->maintenance->enable();
        try {
            $backup = $this->backups->backup($targetDir, $deployment->targetSlug(), $sha);
            if (! $backup->isOk() && ! $backup->isSkipped()) {
                return $backup;
            }
            $moved = $this->mover->move($newDir, $targetDir);
            if (! $moved->isOk()) {
                $suffix = '';
                if ($backup->isOk() && ! $backup->isSkipped()) {
                    $restored = $this->backups->restoreLatest($deployment->targetSlug(), $targetDir);
                    $suffix = $restored->isOk()
                        ? '; the previous version was restored'
                        : '; automatic restore of the previous version ALSO failed (' . $restored->message() . '), manual action required';
                }
                return Result::fail('Could not move the new version into place: ' . $moved->message() . $suffix);
            }
        } finally {
            $this->maintenance->disable();
        }

        $health = $this->health->check($this->healthUrl);
        if (! $health->isOk()) {
            $restored = $this->backups->restoreLatest($deployment->targetSlug(), $targetDir);
            if (! $restored->isOk()) {
                return Result::fail('Health check failed (' . $health->message() . ') and automatic rollback ALSO failed (' . $restored->message() . '). Manual action required: restore the latest backup from the deployward-backups directory under uploads.');
            }
            return Result::fail('Health check failed, rolled back: ' . $health->message());
        }

        return Result::ok($sha, 'Deployed ' . substr($sha, 0, 8));
    }

    private function guardSelf(Deployment $deployment): ?Result
    {
        if ($deployment->targetType() === 'plugin' && $deployment->targetSlug() === $this->selfSlug) {
            return Result::fail('Deployward will not deploy itself');
        }

        return null;
    }

    private function tokenOrNull(Deployment $deployment): ?string
    {
        return $deployment->visibility() === 'private' ? $deployment->token() : null;
    }

    private function finish(Deployment $deployment, string $trigger, Result $result, string $sha): Result
    {
        $status = $result->isSkipped() ? 'skipped' : ($result->isOk() ? 'success' : 'failed');
        $this->log->record(array(
            'deployment_id' => $deployment->id(),
            'sha' => $sha,
            'trigger' => $trigger,
            'status' => $status,
            'message' => $result->message(),
        ));
        if ($status !== 'skipped') {
            $this->notifier->notify(
                sprintf('[Deployward] %s: %s', strtoupper($status), $deployment->repo()),
                $result->message()
            );
        }

        return $result;
    }
}
