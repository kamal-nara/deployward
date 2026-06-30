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

    public function test_normalize_repo_strips_github_url_forms(): void
    {
        $this->assertSame('kamalahmed/licensekit', Deployment::normalizeRepo('https://github.com/kamalahmed/licensekit'));
        $this->assertSame('kamalahmed/licensekit', Deployment::normalizeRepo('https://github.com/kamalahmed/licensekit.git'));
        $this->assertSame('kamalahmed/licensekit', Deployment::normalizeRepo('https://github.com/kamalahmed/licensekit/'));
        $this->assertSame('kamalahmed/licensekit', Deployment::normalizeRepo('github.com/kamalahmed/licensekit'));
        $this->assertSame('kamalahmed/licensekit', Deployment::normalizeRepo('git@github.com:kamalahmed/licensekit.git'));
        $this->assertSame('kamalahmed/licensekit', Deployment::normalizeRepo('kamalahmed/licensekit'));
    }

    public function test_normalize_repo_extracts_owner_repo_from_deep_urls(): void
    {
        $this->assertSame('kamalahmed/licensekit', Deployment::normalizeRepo('https://github.com/kamalahmed/licensekit/tree/main'));
        $this->assertSame('kamalahmed/licensekit', Deployment::normalizeRepo('https://github.com/kamalahmed/licensekit/blob/main/readme.md'));
        $this->assertSame('kamalahmed/licensekit', Deployment::normalizeRepo('https://github.com/kamalahmed/licensekit?tab=readme'));
    }

    public function test_from_array_accepts_full_github_url(): void
    {
        $d = Deployment::fromArray($this->validData(array('repo' => 'https://github.com/kamalahmed/licensekit')));
        $this->assertSame('kamalahmed/licensekit', $d->repo());
    }

    public function test_from_array_accepts_ssh_git_url(): void
    {
        $d = Deployment::fromArray($this->validData(array('repo' => 'git@github.com:kamalahmed/licensekit.git')));
        $this->assertSame('kamalahmed/licensekit', $d->repo());
    }

    public function test_from_array_derives_hyphenated_slug_from_dotted_repo_name(): void
    {
        $data = $this->validData(array('repo' => 'kamalahmed/my.plugin'));
        unset($data['target_slug']);
        $d = Deployment::fromArray($data);
        $this->assertSame('my-plugin', $d->targetSlug());
    }

    public function test_from_array_derives_slug_when_omitted(): void
    {
        $data = $this->validData(array('repo' => 'https://github.com/kamalahmed/licensekit'));
        unset($data['target_slug']);
        $d = Deployment::fromArray($data);
        $this->assertSame('licensekit', $d->targetSlug());
    }
}
