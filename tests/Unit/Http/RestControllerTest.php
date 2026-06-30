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

    public function test_deploy_now_maps_ok_result_to_200(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('find')->with('dw_1')->andReturn($this->deployment());
        $deployer = Mockery::mock(DeployerInterface::class);
        $deployer->shouldReceive('deploy')->once()
            ->with(Mockery::type(Deployment::class), 'manual', false)
            ->andReturn(\Deployward\Support\Result::ok('newsha', 'Deployed newsha'));

        $response = $this->controller($repo, $deployer)->deployNow('dw_1', false);

        $this->assertSame(200, $response->status());
        $this->assertSame('Deployed newsha', $response->data()['message']);
    }

    public function test_deploy_now_maps_failed_result_to_502(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('find')->with('dw_1')->andReturn($this->deployment());
        $deployer = Mockery::mock(DeployerInterface::class);
        $deployer->shouldReceive('deploy')->andReturn(\Deployward\Support\Result::fail('boom'));

        $response = $this->controller($repo, $deployer)->deployNow('dw_1', false);

        $this->assertSame(502, $response->status());
    }

    public function test_deploy_now_unknown_id_404(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('find')->with('nope')->andReturn(null);

        $this->assertSame(404, $this->controller($repo)->deployNow('nope', false)->status());
    }

    public function test_log_returns_entries(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('find')->with('dw_1')->andReturn($this->deployment());
        $log = Mockery::mock(DeployLogInterface::class);
        $log->shouldReceive('recent')->with('dw_1', 20, 0)->andReturn(array(array('id' => 1, 'status' => 'success')));

        $response = $this->controller($repo, null, $log)->deploymentLog('dw_1', 1, 20);

        $this->assertSame(200, $response->status());
        $this->assertSame('success', $response->data()['entries'][0]['status']);
    }

    public function test_branches_success(): void
    {
        $github = Mockery::mock(GitHubClientInterface::class);
        $github->shouldReceive('listBranches')->andReturn(\Deployward\Support\Result::ok(array('main', 'dev')));

        $response = $this->controller(Mockery::mock(DeploymentRepositoryInterface::class), null, null, $github)
            ->branches(array('repo' => 'Nara-IT/nara-core', 'visibility' => 'public'));

        $this->assertSame(200, $response->status());
        $this->assertSame(array('main', 'dev'), $response->data()['branches']);
    }

    public function test_branches_failure_502_without_token_leak(): void
    {
        $github = Mockery::mock(GitHubClientInterface::class);
        $github->shouldReceive('listBranches')->andReturn(\Deployward\Support\Result::fail('GitHub returned HTTP 401'));

        $response = $this->controller(Mockery::mock(DeploymentRepositoryInterface::class), null, null, $github)
            ->branches(array('repo' => 'Nara-IT/p', 'visibility' => 'private', 'token' => 'ghp_secret'));

        $this->assertSame(502, $response->status());
        $this->assertStringNotContainsString('ghp_secret', wp_json_encode_safe($response->data()));
    }

    public function test_rollback_maps_ok_result_to_200(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('find')->with('dw_1')->andReturn($this->deployment());
        $deployer = Mockery::mock(DeployerInterface::class);
        $deployer->shouldReceive('rollback')->once()
            ->with(Mockery::type(Deployment::class), 'manual-rollback')
            ->andReturn(\Deployward\Support\Result::ok('/path/to/target', 'Rolled back dw_1'));

        $response = $this->controller($repo, $deployer)->rollback('dw_1');

        $this->assertSame(200, $response->status());
    }

    public function test_rollback_unknown_id_404(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('find')->with('nope')->andReturn(null);

        $this->assertSame(404, $this->controller($repo)->rollback('nope')->status());
    }

    public function test_created_id_is_lowercase_and_sanitize_key_stable(): void
    {
        Functions\when('wp_generate_password')->justReturn('AbCdEfGhIjKl');
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $captured = null;
        $repo->shouldReceive('save')->once()->with(Mockery::on(function ($d) use (&$captured) {
            $captured = $d->id();
            return true;
        }));

        $response = $this->controller($repo)->saveDeployment(array(
            'repo' => 'owner/repo', 'branch' => 'main', 'visibility' => 'public',
            'target_type' => 'plugin', 'target_slug' => 'sample-plugin',
        ));

        $this->assertSame(201, $response->status());
        $this->assertSame(strtolower($captured), $captured, 'Generated id must be lowercase so it survives sanitize_key in the route');
        $this->assertStringStartsWith('dw_', $captured);
    }

    public function test_webhook_info_returns_secret_for_known_id(): void
    {
        $deployment = Deployment::fromArray(array(
            'id' => 'dw_1', 'repo' => 'o/r', 'branch' => 'main', 'visibility' => 'public',
            'target_type' => 'plugin', 'target_slug' => 'sample', 'token' => '', 'webhook_secret' => 'whsecret',
        ));
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('find')->with('dw_1')->andReturn($deployment);

        $response = $this->controller($repo)->webhookInfo('dw_1');

        $this->assertSame(200, $response->status());
        $this->assertSame('whsecret', $response->data()['secret']);
    }

    public function test_webhook_info_unknown_id_404(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('find')->with('nope')->andReturn(null);

        $this->assertSame(404, $this->controller($repo)->webhookInfo('nope')->status());
    }

    public function test_branches_normalizes_a_github_url(): void
    {
        $github = Mockery::mock(GitHubClientInterface::class);
        $github->shouldReceive('listBranches')->once()->with('kamalahmed/licensekit', null)->andReturn(\Deployward\Support\Result::ok(array('main')));
        $response = $this->controller(Mockery::mock(DeploymentRepositoryInterface::class), null, null, $github)
            ->branches(array('repo' => 'https://github.com/kamalahmed/licensekit', 'visibility' => 'public'));
        $this->assertSame(200, $response->status());
    }

    public function test_branches_rejects_invalid_repo_with_422_without_calling_github(): void
    {
        $github = Mockery::mock(GitHubClientInterface::class);
        $github->shouldNotReceive('listBranches');
        $response = $this->controller(Mockery::mock(DeploymentRepositoryInterface::class), null, null, $github)
            ->branches(array('repo' => 'notarepo', 'visibility' => 'public'));
        $this->assertSame(422, $response->status());
    }

    public function test_save_with_url_and_empty_slug_normalizes_and_derives(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('save')->once()->with(Mockery::on(function ($d) {
            return $d->repo() === 'kamalahmed/licensekit' && $d->targetSlug() === 'licensekit';
        }));
        $response = $this->controller($repo)->saveDeployment(array(
            'repo' => 'https://github.com/kamalahmed/licensekit', 'branch' => 'main',
            'visibility' => 'public', 'target_type' => 'plugin',
        ));
        $this->assertSame(201, $response->status());
    }
}

namespace Deployward\Tests\Unit\Http;

if (! function_exists('Deployward\\Tests\\Unit\\Http\\wp_json_encode_safe')) {
    function wp_json_encode_safe($data): string
    {
        return json_encode($data);
    }
}
