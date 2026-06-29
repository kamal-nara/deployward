<?php

namespace Deployward\Http;

use Deployward\Config\Deployment;
use Deployward\Config\DeploymentRepositoryInterface;
use Deployward\Deploy\DeployerInterface;
use Deployward\GitHub\GitHubClientInterface;
use Deployward\Log\DeployLogInterface;

final class RestController
{
    /** @var DeploymentRepositoryInterface */
    private $repository;
    /** @var DeployerInterface */
    private $deployer;
    /** @var DeployLogInterface */
    private $log;
    /** @var GitHubClientInterface */
    private $github;

    public function __construct(
        DeploymentRepositoryInterface $repository,
        DeployerInterface $deployer,
        DeployLogInterface $log,
        GitHubClientInterface $github
    ) {
        $this->repository = $repository;
        $this->deployer = $deployer;
        $this->log = $log;
        $this->github = $github;
    }

    public function listDeployments(): ApiResponse
    {
        $rows = array();
        foreach ($this->repository->all() as $deployment) {
            $rows[] = $this->present($deployment);
        }

        return ApiResponse::ok(array('deployments' => $rows));
    }

    public function saveDeployment(array $params): ApiResponse
    {
        $id = isset($params['id']) && $params['id'] !== '' ? sanitize_key($params['id']) : '';
        $existing = $id !== '' ? $this->repository->find($id) : null;

        $token = isset($params['token']) ? (string) $params['token'] : '';
        if ($token === '' && $existing !== null) {
            $token = $existing->token();
        }

        $secret = $existing !== null
            ? $existing->webhookSecret()
            : wp_generate_password(32, false);

        if ($id === '') {
            $id = 'dw_' . substr(wp_generate_password(24, false), 0, 12);
        }

        try {
            $deployment = Deployment::fromArray(array(
                'id' => $id,
                'repo' => isset($params['repo']) ? $params['repo'] : '',
                'branch' => isset($params['branch']) ? $params['branch'] : 'main',
                'visibility' => isset($params['visibility']) ? $params['visibility'] : 'public',
                'target_type' => isset($params['target_type']) ? $params['target_type'] : 'plugin',
                'target_slug' => isset($params['target_slug']) ? $params['target_slug'] : '',
                'token' => $token,
                'webhook_secret' => $secret,
                'last_deployed_sha' => $existing !== null ? $existing->lastDeployedSha() : '',
            ));
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        $this->repository->save($deployment);

        return ApiResponse::ok(
            array('deployment' => $this->present($deployment)),
            $existing !== null ? 200 : 201
        );
    }

    public function deleteDeployment(string $id): ApiResponse
    {
        if ($this->repository->find($id) === null) {
            return ApiResponse::error('Deployment not found', 404);
        }
        $this->repository->delete($id);

        return ApiResponse::ok(array('deleted' => $id));
    }

    private function present(Deployment $deployment): array
    {
        return array(
            'id' => $deployment->id(),
            'repo' => $deployment->repo(),
            'branch' => $deployment->branch(),
            'visibility' => $deployment->visibility(),
            'target_type' => $deployment->targetType(),
            'target_slug' => $deployment->targetSlug(),
            'last_deployed_sha' => $deployment->lastDeployedSha(),
            'has_token' => $deployment->token() !== '',
        );
    }
}
