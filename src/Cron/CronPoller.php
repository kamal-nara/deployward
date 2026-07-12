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
    const LAST_POLLS_OPTION = 'deployward_last_polls';
    const GRACE_SECONDS = 30;

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
        $lastPolls = get_option(self::LAST_POLLS_OPTION, array());
        if (! is_array($lastPolls)) {
            $lastPolls = array();
        }
        $nextPolls = array();

        foreach ($this->repository->all() as $deployment) {
            if (! $deployment->deploysOnSchedule()) {
                continue;
            }
            $nextPolls[$deployment->id()] = $this->pollIfDue($deployment, $lastPolls);
        }

        update_option(self::LAST_POLLS_OPTION, $nextPolls, false);
    }

    private function pollIfDue(Deployment $deployment, array $lastPolls): int
    {
        $last = isset($lastPolls[$deployment->id()]) ? (int) $lastPolls[$deployment->id()] : 0;
        $due = time() >= $last + ($deployment->pollInterval() * 60) - self::GRACE_SECONDS;
        if (! $due) {
            return $last;
        }
        $this->pollOne($deployment);

        return time();
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
