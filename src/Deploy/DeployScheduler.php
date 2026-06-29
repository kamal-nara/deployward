<?php

namespace Deployward\Deploy;

use Deployward\Container;

class DeployScheduler
{
    const HOOK = 'deployward_run_deploy';

    /** @var Container */
    private $container;

    public function __construct($container)
    {
        $this->container = $container;
    }

    public function schedule(string $deploymentId, string $trigger, bool $force = false): void
    {
        wp_schedule_single_event(time(), self::HOOK, array($deploymentId, $trigger, $force));
        spawn_cron();
    }

    public function run(string $deploymentId, string $trigger, bool $force = false): void
    {
        $deployment = $this->container->repository()->find($deploymentId);
        if ($deployment === null) {
            return;
        }
        $this->container->deployer()->deploy($deployment, $trigger, $force);
    }
}
