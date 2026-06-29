<?php

namespace Deployward\Tests\Unit\Http;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\Config\Deployment;
use Deployward\Config\DeploymentRepositoryInterface;
use Deployward\Deploy\DeployerInterface;
use Deployward\GitHub\GitHubClientInterface;
use Deployward\Http\RestController;
use Deployward\Log\DeployLogInterface;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class RestControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('wp_generate_password')->justReturn('abcdef123456');
        Functions\when('sanitize_key')->returnArg();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function deployment(string $token = 'ghp_x'): Deployment
    {
        return Deployment::fromArray(array(
            'id' => 'dw_1', 'repo' => 'Nara-IT/nara-core', 'branch' => 'main',
            'visibility' => 'private', 'target_type' => 'plugin', 'target_slug' => 'nara-core',
            'token' => $token, 'webhook_secret' => 'whsec', 'last_deployed_sha' => 'abc1234',
        ));
    }

    private function controller($repo, $deployer = null, $log = null, $github = null): RestController
    {
        return new RestController(
            $repo,
            $deployer ?: Mockery::mock(DeployerInterface::class),
            $log ?: Mockery::mock(DeployLogInterface::class),
            $github ?: Mockery::mock(GitHubClientInterface::class)
        );
    }

    public function test_list_returns_token_free_dicts(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('all')->andReturn(array('dw_1' => $this->deployment()));

        $response = $this->controller($repo)->listDeployments();

        $this->assertSame(200, $response->status());
        $row = $response->data()['deployments'][0];
        $this->assertSame('Nara-IT/nara-core', $row['repo']);
        $this->assertArrayNotHasKey('token', $row);
        $this->assertArrayNotHasKey('webhook_secret', $row);
        $this->assertTrue($row['has_token']);
    }

    public function test_save_creates_with_generated_id_and_secret(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('save')->once()->with(Mockery::type(Deployment::class));

        $response = $this->controller($repo)->saveDeployment(array(
            'repo' => 'Nara-IT/nara-core', 'branch' => 'main', 'visibility' => 'public',
            'target_type' => 'plugin', 'target_slug' => 'nara-core',
        ));

        $this->assertSame(201, $response->status());
        $this->assertArrayNotHasKey('token', $response->data()['deployment']);
    }

    public function test_save_rejects_invalid_repo_with_422(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldNotReceive('save');

        $response = $this->controller($repo)->saveDeployment(array(
            'repo' => 'not-a-repo', 'branch' => 'main', 'visibility' => 'public',
            'target_type' => 'plugin', 'target_slug' => 'nara-core',
        ));

        $this->assertSame(422, $response->status());
    }

    public function test_save_update_preserves_token_when_blank(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('find')->with('dw_1')->andReturn($this->deployment('ghp_existing'));
        $repo->shouldReceive('save')->once()->with(Mockery::on(function ($d) {
            return $d->token() === 'ghp_existing' && $d->webhookSecret() === 'whsec';
        }));

        $response = $this->controller($repo)->saveDeployment(array(
            'id' => 'dw_1', 'repo' => 'Nara-IT/nara-core', 'branch' => 'develop',
            'visibility' => 'private', 'target_type' => 'plugin', 'target_slug' => 'nara-core',
            'token' => '',
        ));

        $this->assertSame(200, $response->status());
    }

    public function test_delete_unknown_returns_404(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('find')->with('nope')->andReturn(null);

        $response = $this->controller($repo)->deleteDeployment('nope');

        $this->assertSame(404, $response->status());
    }
}
