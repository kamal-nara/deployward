<?php

namespace Deployward\Http;

use Deployward\Config\DeploymentRepositoryInterface;
use Deployward\Deploy\DeployScheduler;
use Deployward\Security\SignatureVerifier;

final class WebhookController
{
    /** @var DeploymentRepositoryInterface */
    private $repository;
    /** @var SignatureVerifier */
    private $verifier;
    /** @var DeployScheduler */
    private $scheduler;

    public function __construct(
        DeploymentRepositoryInterface $repository,
        SignatureVerifier $verifier,
        DeployScheduler $scheduler
    ) {
        $this->repository = $repository;
        $this->verifier = $verifier;
        $this->scheduler = $scheduler;
    }

    public function handle(string $deploymentId, string $rawBody, ?string $signatureHeader, ?string $eventHeader): ApiResponse
    {
        $deployment = $this->repository->find($deploymentId);
        if ($deployment === null) {
            return ApiResponse::error('Deployment not found', 404);
        }
        if (! $this->verifier->verify($rawBody, $signatureHeader, $deployment->webhookSecret())) {
            return ApiResponse::error('Invalid signature', 401);
        }
        if ($eventHeader === 'ping') {
            return ApiResponse::ok(array('message' => 'pong'));
        }
        if ($eventHeader !== 'push') {
            return ApiResponse::ok(array('message' => 'event ignored'));
        }
        $payload = json_decode($rawBody, true);
        $ref = is_array($payload) && isset($payload['ref']) ? (string) $payload['ref'] : '';
        if ($ref !== 'refs/heads/' . $deployment->branch()) {
            return ApiResponse::ok(array('message' => 'branch ignored'));
        }
        $this->scheduler->schedule($deployment->id(), 'webhook', false);

        return ApiResponse::ok(array('message' => 'queued'), 202);
    }
}
