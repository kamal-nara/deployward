<?php

namespace Deployward\Tests\Unit\Security;

use Deployward\Config\Deployment;
use Deployward\Config\DeploymentRepositoryInterface;
use Deployward\Notify\NotifierInterface;
use Deployward\Security\TokenReadabilityGuard;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class TokenReadabilityGuardTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    const ADMIN_URL = 'https://nara.local/wp-admin/admin.php?page=deployward';

    private function deployment(array $overrides = array()): Deployment
    {
        return Deployment::fromArray(array_merge(array(
            'id' => 'dw_1',
            'repo' => 'Nara-IT/nara-core',
            'branch' => 'main',
            'visibility' => 'private',
            'target_type' => 'plugin',
            'target_slug' => 'nara-core',
            'token' => '',
            'token_unreadable' => true,
            'webhook_secret' => 'whsec',
        ), $overrides));
    }

    private function guard($repository, $notifier): TokenReadabilityGuard
    {
        return new TokenReadabilityGuard($repository, $notifier, self::ADMIN_URL);
    }

    public function test_an_unreadable_token_records_the_state_and_sends_one_alert(): void
    {
        $deployment = $this->deployment();
        $repository = Mockery::mock(DeploymentRepositoryInterface::class);
        $repository->shouldReceive('find')->with('dw_1')->andReturn($deployment);
        $repository->shouldReceive('recordTokenState')->once()->with('dw_1', Mockery::on(function ($fields) {
            return array_keys($fields) === array('token_unreadable_at')
                && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $fields['token_unreadable_at']) === 1;
        }));
        $notifier = Mockery::mock(NotifierInterface::class);
        $notifier->shouldReceive('notify')->once()->with(
            Mockery::on(function ($subject) {
                return strpos($subject, 'Nara-IT/nara-core') !== false && strpos($subject, 'token') !== false;
            }),
            Mockery::type('string'),
            NotifierInterface::KIND_ALERT
        );

        $this->assertFalse($this->guard($repository, $notifier)->check($deployment));
    }

    public function test_the_alert_explains_what_happened_what_stopped_and_how_to_fix_it(): void
    {
        $deployment = $this->deployment();
        $repository = Mockery::mock(DeploymentRepositoryInterface::class);
        $repository->shouldReceive('find')->andReturn($deployment);
        $repository->shouldReceive('recordTokenState');
        $body = '';
        $notifier = Mockery::mock(NotifierInterface::class);
        $notifier->shouldReceive('notify')->once()->andReturnUsing(function ($subject, $text) use (&$body) {
            $body = $text;
        });

        $this->guard($repository, $notifier)->check($deployment);

        $this->assertStringContainsString('can no longer read the saved GitHub token for Nara-IT/nara-core', $body);
        $this->assertStringContainsString('WordPress secret keys changed', $body);
        $this->assertStringContainsString('saved on another site', $body);
        $this->assertStringContainsString('Deployments of Nara-IT/nara-core are stopped', $body);
        $this->assertStringContainsString('until someone pastes the token again in Deployward', $body);
        $this->assertStringContainsString(self::ADMIN_URL, $body);
        $this->assertStringContainsString('own key', $body);
    }

    public function test_a_state_that_is_already_recorded_sends_nothing_more(): void
    {
        $deployment = $this->deployment(array('token_unreadable_at' => '2026-11-06T10:00:00+00:00'));
        $repository = Mockery::mock(DeploymentRepositoryInterface::class);
        $repository->shouldNotReceive('find');
        $repository->shouldNotReceive('recordTokenState');
        $notifier = Mockery::mock(NotifierInterface::class);
        $notifier->shouldNotReceive('notify');

        $this->assertFalse($this->guard($repository, $notifier)->check($deployment));
    }

    public function test_a_stale_snapshot_does_not_alert_again_when_another_run_already_did(): void
    {
        $snapshot = $this->deployment();
        $fresh = $this->deployment(array('token_unreadable_at' => '2026-11-09T08:00:00+00:00'));
        $repository = Mockery::mock(DeploymentRepositoryInterface::class);
        $repository->shouldReceive('find')->with('dw_1')->andReturn($fresh);
        $repository->shouldNotReceive('recordTokenState');
        $notifier = Mockery::mock(NotifierInterface::class);
        $notifier->shouldNotReceive('notify');

        $this->assertFalse($this->guard($repository, $notifier)->check($snapshot));
    }

    public function test_a_readable_token_passes_without_touching_anything(): void
    {
        $deployment = $this->deployment(array('token' => 'ghp_x', 'token_unreadable' => false));
        $repository = Mockery::mock(DeploymentRepositoryInterface::class);
        $repository->shouldNotReceive('find');
        $repository->shouldNotReceive('recordTokenState');
        $notifier = Mockery::mock(NotifierInterface::class);
        $notifier->shouldNotReceive('notify');

        $this->assertTrue($this->guard($repository, $notifier)->check($deployment));
    }

    public function test_a_readable_token_clears_a_state_left_from_an_earlier_failure(): void
    {
        $deployment = $this->deployment(array(
            'token' => 'ghp_x',
            'token_unreadable' => false,
            'token_unreadable_at' => '2026-11-06T10:00:00+00:00',
        ));
        $repository = Mockery::mock(DeploymentRepositoryInterface::class);
        $repository->shouldReceive('recordTokenState')->once()->with('dw_1', array('token_unreadable_at' => ''));
        $notifier = Mockery::mock(NotifierInterface::class);
        $notifier->shouldNotReceive('notify');

        $this->assertTrue($this->guard($repository, $notifier)->check($deployment));
    }

    public function test_a_public_deployment_is_never_alerted_about(): void
    {
        $deployment = $this->deployment(array('visibility' => 'public'));
        $repository = Mockery::mock(DeploymentRepositoryInterface::class);
        $repository->shouldNotReceive('find');
        $repository->shouldNotReceive('recordTokenState');
        $notifier = Mockery::mock(NotifierInterface::class);
        $notifier->shouldNotReceive('notify');

        $this->assertTrue($this->guard($repository, $notifier)->check($deployment));
    }

    public function test_the_deploy_failure_message_says_the_same_in_plain_words(): void
    {
        $message = TokenReadabilityGuard::deployFailureMessage('Nara-IT/nara-core');

        $this->assertStringContainsString('cannot read the saved GitHub token for Nara-IT/nara-core', $message);
        $this->assertStringContainsString('WordPress secret keys changed', $message);
        $this->assertStringContainsString('Deployments of Nara-IT/nara-core are stopped', $message);
        $this->assertStringContainsString('pastes the token again in Deployward', $message);
        $this->assertStringNotContainsString('Decryption failed', $message);
    }
}
