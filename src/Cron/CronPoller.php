<?php

namespace Deployward\Cron;

use Deployward\Config\Deployment;
use Deployward\Config\DeploymentRepositoryInterface;
use Deployward\Deploy\DeploySchedulerInterface;
use Deployward\GitHub\GitHubClientInterface;

final class CronPoller
{
    const HOOK = 'deployward_poll';
    const INTERVAL = 'deployward_5min';

    /** @var DeploymentRepositoryInterface */
    private $repository;
    /** @var GitHubClientInterface */
    private $github;
    /** @var DeploySchedulerInterface */
    private $scheduler;

    public function __construct(
        DeploymentRepositoryInterface $repository,
        GitHubClientInterface $github,
        DeploySchedulerInterface $scheduler
    ) {
        $this->repository = $repository;
        $this->github = $github;
        $this->scheduler = $scheduler;
    }

    public function poll(): void
    {
        foreach ($this->repository->all() as $deployment) {
            $this->pollOne($deployment);
        }
    }

    private function pollOne(Deployment $deployment): void
    {
        $token = $deployment->visibility() === 'private' ? $deployment->token() : null;
        $result = $this->github->resolveSha($deployment->repo(), $deployment->branch(), $token);
        if (! $result->isOk()) {
            return;
        }
        if ((string) $result->data() === $deployment->lastDeployedSha()) {
            return;
        }
        $this->scheduler->schedule($deployment->id(), 'cron', false);
    }

    public static function addInterval(array $schedules): array
    {
        return array_merge($schedules, array(
            self::INTERVAL => array('interval' => 300, 'display' => 'Every 5 minutes (Deployward)'),
        ));
    }
}
