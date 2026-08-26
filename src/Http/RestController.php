<?php

namespace Deployward\Http;

use Deployward\Config\Deployment;
use Deployward\Config\DeploymentRepositoryInterface;
use Deployward\Deploy\DeployerInterface;
use Deployward\GitHub\GitHubClientInterface;
use Deployward\Log\DeployLogInterface;

final class RestController
{
    const HTTP_UNAUTHORIZED = 401;

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

        $submitted = isset($params['token']) ? (string) $params['token'] : '';
        $clearToken = filter_var(
            isset($params['clear_token']) ? $params['clear_token'] : false,
            FILTER_VALIDATE_BOOLEAN
        );
        $token = ($submitted === '' && $existing !== null && ! $clearToken) ? $existing->token() : $submitted;

        $secret = $existing !== null
            ? $existing->webhookSecret()
            : wp_generate_password(32, false);

        if ($id === '') {
            $id = 'dw_' . strtolower(substr(wp_generate_password(24, false), 0, 12));
        }

        try {
            $deployment = Deployment::fromArray(array_merge($this->tokenState($existing, $submitted, $clearToken), array(
                'id' => $id,
                'repo' => isset($params['repo']) ? $params['repo'] : '',
                'branch' => isset($params['branch']) ? $params['branch'] : 'main',
                'visibility' => isset($params['visibility']) ? $params['visibility'] : 'public',
                'target_type' => isset($params['target_type']) ? $params['target_type'] : 'plugin',
                'target_slug' => isset($params['target_slug']) ? $params['target_slug'] : '',
                'token' => $token,
                'webhook_secret' => $secret,
                'last_deployed_sha' => $existing !== null ? $existing->lastDeployedSha() : '',
                'webhook_deploy' => isset($params['webhook_deploy']) ? $params['webhook_deploy'] : false,
                'poll_deploy' => isset($params['poll_deploy']) ? $params['poll_deploy'] : false,
                'poll_interval' => isset($params['poll_interval']) ? $params['poll_interval'] : 5,
            )));
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        $this->repository->save($deployment, $clearToken);

        return ApiResponse::ok(
            array('deployment' => $this->present($deployment)),
            $existing !== null ? 200 : 201
        );
    }

    private function tokenState(?Deployment $existing, string $submitted, bool $clearToken): array
    {
        $blank = array(
            'token_last4' => '',
            'token_expires_at' => '',
            'token_checked_at' => '',
            'token_alerted' => '',
        );
        if ($clearToken || $existing === null) {
            return array_merge($blank, array('token_last4' => Deployment::last4($submitted)));
        }
        if ($submitted !== '' && $submitted !== $existing->token()) {
            return array_merge($blank, array('token_last4' => Deployment::last4($submitted)));
        }

        return array(
            'token_last4' => $existing->tokenLast4(),
            'token_expires_at' => $existing->tokenExpiresAt(),
            'token_checked_at' => $existing->tokenCheckedAt(),
            'token_alerted' => $existing->tokenAlerted(),
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

    public function checkToken(string $id): ApiResponse
    {
        $deployment = $this->repository->find($id);
        if ($deployment === null) {
            return ApiResponse::error('Deployment not found', 404);
        }
        $token = $deployment->token() !== '' ? $deployment->token() : null;
        $result = $this->github->resolveSha($deployment->repo(), $deployment->branch(), $token);
        if (! $result->isOk()) {
            if ($result->data() === self::HTTP_UNAUTHORIZED) {
                return ApiResponse::ok(array('token_status' => 'rejected', 'message' => $result->message()));
            }

            return ApiResponse::error($result->message(), 502);
        }
        $checked = $this->recordTokenCheck($deployment);

        return ApiResponse::ok(array(
            'token_status' => $checked->tokenStatus(),
            'token_expires_at' => $checked->tokenExpiresAt(),
            'token_checked_at' => $checked->tokenCheckedAt(),
            'token_last4' => $checked->tokenLast4(),
            'message' => 'GitHub accepted the token for ' . $checked->repo() . '.',
        ));
    }

    private function recordTokenCheck(Deployment $deployment): Deployment
    {
        $checked = $deployment->withTokenExpiry(
            $this->github->lastTokenExpiry(),
            gmdate(\DateTimeInterface::ATOM)
        );
        $this->repository->save($checked);

        return $checked;
    }

    public function branches(array $params): ApiResponse
    {
        $repo = \Deployward\Config\Deployment::normalizeRepo(isset($params['repo']) ? (string) $params['repo'] : '');
        if (! preg_match('#^[\w.-]+/[\w.-]+$#', $repo)) {
            return ApiResponse::error('repo must be a GitHub owner/repo or URL', 422);
        }
        $visibility = isset($params['visibility']) ? (string) $params['visibility'] : 'public';
        $known = $this->findById(isset($params['id']) ? (string) $params['id'] : '');
        $token = $this->branchToken($params, $visibility, $known);
        $result = $this->github->listBranches($repo, $token);
        if (! $result->isOk()) {
            return ApiResponse::error($result->message(), 502);
        }
        if ($known !== null && $known->visibility() === 'private') {
            $this->recordTokenCheck($known);
        }

        return ApiResponse::ok(array('branches' => $result->data()));
    }

    private function findById(string $id): ?Deployment
    {
        $clean = $id !== '' ? sanitize_key($id) : '';

        return $clean !== '' ? $this->repository->find($clean) : null;
    }

    private function branchToken(array $params, string $visibility, ?Deployment $known): ?string
    {
        if ($visibility !== 'private') {
            return null;
        }
        $submitted = isset($params['token']) ? (string) $params['token'] : '';
        if ($submitted !== '') {
            return $submitted;
        }
        $saved = $known !== null ? $known->token() : '';

        return $saved !== '' ? $saved : null;
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
            'token_last4' => $deployment->tokenLast4(),
            'token_status' => $deployment->tokenStatus(),
            'token_expires_at' => $deployment->tokenExpiresAt(),
            'token_checked_at' => $deployment->tokenCheckedAt(),
            'webhook_deploy' => $deployment->deploysOnPush(),
            'poll_deploy' => $deployment->deploysOnSchedule(),
            'poll_interval' => $deployment->pollInterval(),
        );
    }
}
