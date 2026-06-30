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
            $id = 'dw_' . strtolower(substr(wp_generate_password(24, false), 0, 12));
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

    public function deployNow(string $id, bool $force): ApiResponse
    {
        $deployment = $this->repository->find($id);
        if ($deployment === null) {
            return ApiResponse::error('Deployment not found', 404);
        }
        $result = $this->deployer->deploy($deployment, 'manual', $force);

        return $this->fromResult($result);
    }

    public function rollback(string $id): ApiResponse
    {
        $deployment = $this->repository->find($id);
        if ($deployment === null) {
            return ApiResponse::error('Deployment not found', 404);
        }
        $result = $this->deployer->rollback($deployment, 'manual-rollback');

        return $this->fromResult($result);
    }

    public function deploymentLog(string $id, int $page, int $perPage = 20): ApiResponse
    {
        if ($this->repository->find($id) === null) {
            return ApiResponse::error('Deployment not found', 404);
        }
        $offset = max(0, ($page - 1)) * $perPage;
        $entries = $this->log->recent($id, $perPage, $offset);

        return ApiResponse::ok(array('entries' => $entries, 'page' => $page));
    }

    public function webhookInfo(string $id): ApiResponse
    {
        $deployment = $this->repository->find($id);
        if ($deployment === null) {
            return ApiResponse::error('Deployment not found', 404);
        }

        return ApiResponse::ok(array('secret' => $deployment->webhookSecret()));
    }

    public function branches(array $params): ApiResponse
    {
        $repo = \Deployward\Config\Deployment::normalizeRepo(isset($params['repo']) ? (string) $params['repo'] : '');
        if (! preg_match('#^[\w.-]+/[\w.-]+$#', $repo)) {
            return ApiResponse::error('repo must be a GitHub owner/repo or URL', 422);
        }
        $visibility = isset($params['visibility']) ? (string) $params['visibility'] : 'public';
        $token = ($visibility === 'private' && isset($params['token'])) ? (string) $params['token'] : null;
        if ($token === '') {
            $token = null;
        }
        $result = $this->github->listBranches($repo, $token);
        if (! $result->isOk()) {
            return ApiResponse::error($result->message(), 502);
        }

        return ApiResponse::ok(array('branches' => $result->data()));
    }

    private function fromResult(\Deployward\Support\Result $result): ApiResponse
    {
        if (! $result->isOk()) {
            return ApiResponse::error($result->message(), 502, array('status' => 'failed'));
        }
        $status = $result->isSkipped() ? 'skipped' : 'success';
        $data = array('status' => $status, 'message' => $result->message());
        if (! $result->isSkipped() && is_string($result->data())) {
            $data['sha'] = $result->data();
        }

        return ApiResponse::ok($data);
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
