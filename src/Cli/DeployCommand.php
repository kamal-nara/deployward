<?php

namespace Deployward\Cli;

use Deployward\Config\Deployment;
use Deployward\Config\DeploymentRepositoryInterface;
use Deployward\Deploy\DeployerInterface;
use Deployward\Log\DeployLogInterface;

final class DeployCommand
{
    /** @var DeploymentRepositoryInterface */
    private $repository;
    /** @var DeployerInterface */
    private $deployer;
    /** @var DeployLogInterface */
    private $log;

    public function __construct(
        DeploymentRepositoryInterface $repository,
        DeployerInterface $deployer,
        DeployLogInterface $log
    ) {
        $this->repository = $repository;
        $this->deployer = $deployer;
        $this->log = $log;
    }

    /**
     * Adds a deployment.
     *
     * ## OPTIONS
     *
     * --repo=<owner-repo>
     * : The GitHub repository in owner/repo format.
     *
     * [--slug=<slug>]
     * : Optional. Target plugin/theme slug; defaults to the repository name.
     *
     * [--id=<id>]
     * : Optional stable id. Generated when omitted.
     *
     * [--branch=<branch>]
     * : Branch to watch. Default: main.
     *
     * [--type=<type>]
     * : plugin, theme, or mu-plugin. Default: plugin.
     *
     * [--visibility=<visibility>]
     * : public or private. Default: public.
     *
     * [--token=<token>]
     * : GitHub fine-grained PAT for private repos.
     *
     * [--webhook-deploy]
     * : Deploy instantly when GitHub pushes to the watched branch (requires webhook setup).
     *
     * [--poll-deploy]
     * : Check for new commits on a schedule and deploy them.
     *
     * [--poll-interval=<minutes>]
     * : How often to check for new commits when auto deploy is on: 5, 15, 30, or 60. Default: 5.
     */
    public function add(array $args, array $assoc): void
    {
        $id = sanitize_key(isset($assoc['id']) ? $assoc['id'] : wp_generate_password(8, false));
        try {
            $deployment = Deployment::fromArray(array(
                'id' => $id,
                'repo' => isset($assoc['repo']) ? $assoc['repo'] : '',
                'branch' => isset($assoc['branch']) ? $assoc['branch'] : 'main',
                'visibility' => isset($assoc['visibility']) ? $assoc['visibility'] : 'public',
                'target_type' => isset($assoc['type']) ? $assoc['type'] : 'plugin',
                'target_slug' => isset($assoc['slug']) ? $assoc['slug'] : '',
                'token' => isset($assoc['token']) ? $assoc['token'] : '',
                'webhook_secret' => wp_generate_password(32, false),
                'webhook_deploy' => isset($assoc['webhook-deploy']),
                'poll_deploy' => isset($assoc['poll-deploy']),
                'poll_interval' => isset($assoc['poll-interval']) ? (int) $assoc['poll-interval'] : 5,
            ));
        } catch (\InvalidArgumentException $e) {
            \WP_CLI::error($e->getMessage());
            return;
        }
        $this->repository->save($deployment);
        \WP_CLI::success('Added deployment ' . $id . ' (' . $deployment->repo() . ')');
    }

    /**
     * Lists deployments.
     *
     * @subcommand list
     */
    public function list(array $args, array $assoc): void
    {
        foreach ($this->repository->all() as $deployment) {
            \WP_CLI::line(sprintf(
                '%s  %s@%s  -> %s/%s  [%s]  %s',
                $deployment->id(),
                $deployment->repo(),
                $deployment->branch(),
                $deployment->targetType(),
                $deployment->targetSlug(),
                $deployment->lastDeployedSha() === '' ? 'never' : substr($deployment->lastDeployedSha(), 0, 8),
                $this->modeLabel($deployment)
            ));
        }
    }

    private function modeLabel(Deployment $deployment): string
    {
        $onPush = $deployment->deploysOnPush();
        $onSchedule = $deployment->deploysOnSchedule();
        if ($onPush && $onSchedule) {
            return 'webhook+poll:' . $deployment->pollInterval() . 'm';
        }
        if ($onPush) {
            return 'webhook';
        }
        if ($onSchedule) {
            return 'poll:' . $deployment->pollInterval() . 'm';
        }

        return 'manual';
    }

    /**
     * Deploys a deployment now.
     *
     * ## OPTIONS
     *
     * <id>
     * : The deployment id.
     *
     * [--force]
     * : Deploy even when the latest commit is already deployed.
     */
    public function deploy(array $args, array $assoc): void
    {
        $deployment = $this->requireDeployment($args);
        $force = isset($assoc['force']);
        $result = $this->deployer->deploy($deployment, 'manual', $force);
        if ($result->isOk()) {
            \WP_CLI::success($result->message());
            return;
        }
        \WP_CLI::error($result->message());
    }

    /**
     * Rolls a deployment back to its previous version.
     *
     * ## OPTIONS
     *
     * <id>
     * : The deployment id.
     */
    public function rollback(array $args, array $assoc): void
    {
        $deployment = $this->requireDeployment($args);
        $result = $this->deployer->rollback($deployment);
        if ($result->isOk()) {
            \WP_CLI::success('Rolled back ' . $deployment->id());
            return;
        }
        \WP_CLI::error($result->message());
    }

    /**
     * Shows recent deploy log entries for a deployment.
     *
     * ## OPTIONS
     *
     * <id>
     * : The deployment id.
     */
    public function log(array $args, array $assoc): void
    {
        $id = isset($args[0]) ? $args[0] : '';
        foreach ($this->log->recent($id, 20, 0) as $row) {
            \WP_CLI::line(sprintf(
                '%s  %s  %s  %s',
                isset($row['created_at']) ? $row['created_at'] : '',
                isset($row['status']) ? $row['status'] : '',
                isset($row['sha']) ? substr((string) $row['sha'], 0, 8) : '',
                isset($row['message']) ? $row['message'] : ''
            ));
        }
    }

    private function requireDeployment(array $args): Deployment
    {
        $id = isset($args[0]) ? $args[0] : '';
        $deployment = $this->repository->find($id);
        if ($deployment === null) {
            \WP_CLI::error('No deployment found with id ' . $id);
        }

        return $deployment;
    }
}
