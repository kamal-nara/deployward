<?php

namespace Deployward\Tests\Unit\Http;

use Deployward\Config\Deployment;
use Deployward\Config\DeploymentRepositoryInterface;
use Deployward\Deploy\DeploySchedulerInterface;
use Deployward\Http\WebhookController;
use Deployward\Security\SignatureVerifier;
use Deployward\Security\SignatureVerifierInterface;
use Deployward\Deploy\DeployScheduler;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class WebhookControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private function deployment(string $secret = 'whsec', bool $webhookDeploy = false, bool $pollDeploy = false): Deployment
    {
        return Deployment::fromArray(array(
            'id' => 'dw_abc', 'repo' => 'o/r', 'branch' => 'main', 'visibility' => 'public',
            'target_type' => 'plugin', 'target_slug' => 'sample', 'token' => '', 'webhook_secret' => $secret,
            'webhook_deploy' => $webhookDeploy, 'poll_deploy' => $pollDeploy,
        ));
    }

    private function controller($repo, $verifier, $scheduler): WebhookController
    {
        return new WebhookController($repo, $verifier, $scheduler);
    }

    public function test_unknown_id_returns_404(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('find')->with('nope')->andReturn(null);
        $scheduler = Mockery::mock(DeploySchedulerInterface::class);
        $scheduler->shouldNotReceive('schedule');

        $res = $this->controller($repo, new SignatureVerifier(), $scheduler)
            ->handle('nope', '{}', 'sha256=x', 'push');

        $this->assertSame(404, $res->status());
    }

    public function test_invalid_signature_returns_401_and_does_not_deploy(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('find')->andReturn($this->deployment());
        $verifier = Mockery::mock(SignatureVerifierInterface::class);
        $verifier->shouldReceive('verify')->andReturn(false);
        $scheduler = Mockery::mock(DeploySchedulerInterface::class);
        $scheduler->shouldNotReceive('schedule');

        $res = $this->controller($repo, $verifier, $scheduler)
            ->handle('dw_abc', '{"ref":"refs/heads/main"}', 'sha256=bad', 'push');

        $this->assertSame(401, $res->status());
    }

    public function test_ping_event_returns_pong(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('find')->andReturn($this->deployment());
        $verifier = Mockery::mock(SignatureVerifierInterface::class);
        $verifier->shouldReceive('verify')->andReturn(true);
        $scheduler = Mockery::mock(DeploySchedulerInterface::class);
        $scheduler->shouldNotReceive('schedule');

        $res = $this->controller($repo, $verifier, $scheduler)
            ->handle('dw_abc', '{"zen":"x"}', 'sha256=ok', 'ping');

        $this->assertSame(200, $res->status());
        $this->assertSame('pong', $res->data()['message']);
    }

    public function test_push_to_other_branch_is_ignored(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('find')->andReturn($this->deployment());
        $verifier = Mockery::mock(SignatureVerifierInterface::class);
        $verifier->shouldReceive('verify')->andReturn(true);
        $scheduler = Mockery::mock(DeploySchedulerInterface::class);
        $scheduler->shouldNotReceive('schedule');

        $res = $this->controller($repo, $verifier, $scheduler)
            ->handle('dw_abc', '{"ref":"refs/heads/develop"}', 'sha256=ok', 'push');

        $this->assertSame(200, $res->status());
    }

    public function test_push_to_watched_branch_queues_deploy_202(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('find')->andReturn($this->deployment('whsec', true));
        $verifier = Mockery::mock(SignatureVerifierInterface::class);
        $verifier->shouldReceive('verify')->andReturn(true);
        $scheduler = Mockery::mock(DeploySchedulerInterface::class);
        $scheduler->shouldReceive('schedule')->once()->with('dw_abc', 'webhook', false);

        $res = $this->controller($repo, $verifier, $scheduler)
            ->handle('dw_abc', '{"ref":"refs/heads/main","after":"abc1234"}', 'sha256=ok', 'push');

        $this->assertSame(202, $res->status());
        $this->assertSame('abc1234', $res->data()['sha']);
    }

    public function test_push_to_watched_branch_with_webhook_deploy_off_does_not_queue(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('find')->andReturn($this->deployment('whsec', false));
        $verifier = Mockery::mock(SignatureVerifierInterface::class);
        $verifier->shouldReceive('verify')->andReturn(true);
        $scheduler = Mockery::mock(DeploySchedulerInterface::class);
        $scheduler->shouldNotReceive('schedule');

        $res = $this->controller($repo, $verifier, $scheduler)
            ->handle('dw_abc', '{"ref":"refs/heads/main","after":"abc1234"}', 'sha256=ok', 'push');

        $this->assertSame(200, $res->status());
        $this->assertSame('webhook deploys are disabled for this deployment', $res->data()['message']);
    }

    public function test_push_with_webhook_off_but_poll_on_does_not_queue(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('find')->andReturn($this->deployment('whsec', false, true));
        $verifier = Mockery::mock(SignatureVerifierInterface::class);
        $verifier->shouldReceive('verify')->andReturn(true);
        $scheduler = Mockery::mock(DeploySchedulerInterface::class);
        $scheduler->shouldNotReceive('schedule');

        $res = $this->controller($repo, $verifier, $scheduler)
            ->handle('dw_abc', '{"ref":"refs/heads/main","after":"abc1234"}', 'sha256=ok', 'push');

        $this->assertSame(200, $res->status());
        $this->assertSame('webhook deploys are disabled for this deployment', $res->data()['message']);
    }
}
