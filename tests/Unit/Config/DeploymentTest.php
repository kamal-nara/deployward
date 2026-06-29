<?php

namespace Deployward\Tests\Unit\Config;

use Deployward\Config\Deployment;
use PHPUnit\Framework\TestCase;

final class DeploymentTest extends TestCase
{
    private function validData(array $overrides = array()): array
    {
        return array_merge(array(
            'id' => 'abc123',
            'repo' => 'Nara-IT/nara-core',
            'branch' => 'main',
            'visibility' => 'private',
            'target_type' => 'plugin',
            'target_slug' => 'nara-core',
            'token' => 'ghp_x',
            'webhook_secret' => 'whsec',
            'last_deployed_sha' => '',
        ), $overrides);
    }

    public function test_builds_from_valid_array(): void
    {
        $deployment = Deployment::fromArray($this->validData());

        $this->assertSame('Nara-IT/nara-core', $deployment->repo());
        $this->assertSame('plugin', $deployment->targetType());
        $this->assertSame('nara-core', $deployment->targetSlug());
        $this->assertSame('ghp_x', $deployment->token());
    }

    public function test_rejects_bad_repo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Deployment::fromArray($this->validData(array('repo' => 'not-a-repo')));
    }

    public function test_rejects_unknown_target_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Deployment::fromArray($this->validData(array('target_type' => 'mustache')));
    }

    public function test_with_last_deployed_sha_returns_new_instance(): void
    {
        $deployment = Deployment::fromArray($this->validData());
        $next = $deployment->withLastDeployedSha('deadbeef');

        $this->assertSame('', $deployment->lastDeployedSha());
        $this->assertSame('deadbeef', $next->lastDeployedSha());
        $this->assertNotSame($deployment, $next);
    }

    public function test_rejects_unknown_visibility(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Deployment::fromArray($this->validData(array('visibility' => 'secret')));
    }

    public function test_rejects_missing_required_field(): void
    {
        $data = $this->validData();
        unset($data['branch']);
        $this->expectException(\InvalidArgumentException::class);
        Deployment::fromArray($data);
    }

    public function test_with_token_returns_new_instance(): void
    {
        $deployment = Deployment::fromArray($this->validData());
        $next = $deployment->withToken('ghp_new');

        $this->assertSame('ghp_x', $deployment->token());
        $this->assertSame('ghp_new', $next->token());
        $this->assertNotSame($deployment, $next);
    }
}
