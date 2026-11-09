<?php

namespace Deployward\Tests\Unit\Config;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\Config\Deployment;
use Deployward\Config\DeploymentRepository;
use Deployward\Security\Encryptor;
use Deployward\Security\KeyRing;
use Deployward\Tests\Support\InMemoryKeyStore;
use PHPUnit\Framework\TestCase;

final class DeploymentRepositoryTest extends TestCase
{
    private $store = array();
    private $lastAutoload = null;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->store = array();

        Functions\when('get_option')->alias(function ($key, $default = false) {
            return array_key_exists($key, $this->store) ? $this->store[$key] : $default;
        });
        Functions\when('update_option')->alias(function ($key, $value, $autoload = true) {
            $this->store[$key] = $value;
            $this->lastAutoload = $autoload;
            return true;
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function repository(): DeploymentRepository
    {
        return new DeploymentRepository(new Encryptor(KeyRing::fromMaterial(str_repeat('k', 32), '', new InMemoryKeyStore())));
    }

    private function deployment(): Deployment
    {
        return Deployment::fromArray(array(
            'id' => 'abc',
            'repo' => 'Nara-IT/nara-core',
            'branch' => 'main',
            'visibility' => 'private',
            'target_type' => 'plugin',
            'target_slug' => 'nara-core',
            'token' => 'ghp_secret',
            'webhook_secret' => 'whsec',
        ));
    }

    public function test_save_stores_token_encrypted(): void
    {
        $this->repository()->save($this->deployment());

        $stored = $this->store[DeploymentRepository::OPTION]['abc'];
        $this->assertNotSame('ghp_secret', $stored['token']);
        $this->assertNotSame('', $stored['token']);
    }

    public function test_find_returns_decrypted_token(): void
    {
        $repo = $this->repository();
        $repo->save($this->deployment());

        $found = $repo->find('abc');
        $this->assertSame('ghp_secret', $found->token());
    }

    public function test_delete_removes_deployment(): void
    {
        $repo = $this->repository();
        $repo->save($this->deployment());
        $repo->delete('abc');

        $this->assertNull($repo->find('abc'));
    }

    public function test_save_uses_autoload_off(): void
    {
        $this->repository()->save($this->deployment());

        $this->assertFalse($this->lastAutoload);
    }

    public function test_a_blank_token_keeps_the_stored_ciphertext(): void
    {
        $repo = $this->repository();
        $repo->save($this->deployment());
        $ciphertext = $this->store[DeploymentRepository::OPTION]['abc']['token'];

        $repo->save($this->deployment()->withToken(''));

        $this->assertSame($ciphertext, $this->store[DeploymentRepository::OPTION]['abc']['token']);
        $this->assertSame('ghp_secret', $repo->find('abc')->token());
    }

    public function test_clear_token_blanks_the_stored_token(): void
    {
        $repo = $this->repository();
        $repo->save($this->deployment());

        $repo->save($this->deployment()->withToken(''), true);

        $this->assertSame('', $this->store[DeploymentRepository::OPTION]['abc']['token']);
        $this->assertSame('', $this->store[DeploymentRepository::OPTION]['abc']['token_last4']);
        $this->assertSame('missing', $repo->find('abc')->tokenStatus());
    }

    public function test_a_new_token_stores_the_last_four_characters(): void
    {
        $repo = $this->repository();
        $repo->save($this->deployment());

        $this->assertSame('cret', $this->store[DeploymentRepository::OPTION]['abc']['token_last4']);
    }

    public function test_a_new_token_resets_the_recorded_expiry(): void
    {
        $repo = $this->repository();
        $repo->save(Deployment::fromArray(array_merge($this->deployment()->toArray(), array(
            'token_expires_at' => '2026-09-26T12:34:56+00:00',
            'token_checked_at' => '2026-08-26T00:00:00+00:00',
            'token_alerted' => '14d',
        ))));

        $repo->save($this->deployment()->withToken('ghp_brandnew'));

        $row = $this->store[DeploymentRepository::OPTION]['abc'];
        $this->assertSame('', $row['token_expires_at']);
        $this->assertSame('', $row['token_checked_at']);
        $this->assertSame('', $row['token_alerted']);
        $this->assertSame('dnew', $row['token_last4']);
    }

    public function test_saving_the_same_token_again_keeps_the_recorded_expiry(): void
    {
        $repo = $this->repository();
        $repo->save($this->deployment());
        $stored = $repo->find('abc');

        $repo->save($stored->withTokenExpiry('2026-09-26T12:34:56+00:00', '2026-08-26T00:00:00+00:00'));
        $repo->save($repo->find('abc')->withLastDeployedSha('deadbeef'));

        $this->assertSame('2026-09-26T12:34:56+00:00', $repo->find('abc')->tokenExpiresAt());
    }

    public function test_record_token_state_leaves_a_concurrently_replaced_token_alone(): void
    {
        $repo = $this->repository();
        $repo->save($this->deployment());
        $loaded = $repo->find('abc');

        $repo->save($this->deployment()->withToken('ghp_replacement'));
        $replacement = $this->store[DeploymentRepository::OPTION]['abc']['token'];

        $repo->recordTokenState($loaded->id(), array(
            'token_expires_at' => '2027-09-26T12:34:56+00:00',
            'token_checked_at' => '2026-08-27T09:00:00+00:00',
        ));

        $row = $this->store[DeploymentRepository::OPTION]['abc'];
        $this->assertSame($replacement, $row['token']);
        $this->assertSame('ment', $row['token_last4']);
        $this->assertSame('ghp_replacement', $repo->find('abc')->token());
        $this->assertSame('2027-09-26T12:34:56+00:00', $row['token_expires_at']);
    }

    public function test_record_token_state_ignores_keys_it_does_not_own(): void
    {
        $repo = $this->repository();
        $repo->save($this->deployment());

        $repo->recordTokenState('abc', array(
            'token_alerted' => '14d',
            'token_last4' => 'hack',
            'token' => 'plaintext',
            'repo' => 'evil/repo',
            'last_deployed_sha' => 'deadbeef',
        ));

        $row = $this->store[DeploymentRepository::OPTION]['abc'];
        $this->assertSame('14d', $row['token_alerted']);
        $this->assertSame('cret', $row['token_last4']);
        $this->assertNotSame('plaintext', $row['token']);
        $this->assertSame('Nara-IT/nara-core', $row['repo']);
        $this->assertSame('', $row['last_deployed_sha']);
    }

    public function test_record_token_state_is_a_no_op_for_an_unknown_id(): void
    {
        $repo = $this->repository();
        $repo->save($this->deployment());
        $before = $this->store[DeploymentRepository::OPTION];

        $repo->recordTokenState('nope', array('token_alerted' => '14d'));

        $this->assertSame($before, $this->store[DeploymentRepository::OPTION]);
        $this->assertArrayNotHasKey('nope', $this->store[DeploymentRepository::OPTION]);
    }

    public function test_record_token_state_does_not_write_when_nothing_changes(): void
    {
        $repo = $this->repository();
        $repo->save($this->deployment());
        $repo->recordTokenState('abc', array('token_expires_at' => '2027-09-26T12:34:56+00:00'));
        $this->lastAutoload = 'untouched';

        $repo->recordTokenState('abc', array('token_expires_at' => '2027-09-26T12:34:56+00:00'));

        $this->assertSame('untouched', $this->lastAutoload);
    }

    public function test_record_token_state_clears_a_stale_expiry_with_an_empty_string(): void
    {
        $repo = $this->repository();
        $repo->save($this->deployment());
        $repo->recordTokenState('abc', array('token_expires_at' => '2026-01-01T00:00:00+00:00'));

        $repo->recordTokenState('abc', array('token_expires_at' => ''));

        $this->assertSame('', $this->store[DeploymentRepository::OPTION]['abc']['token_expires_at']);
        $this->assertSame('', $repo->find('abc')->tokenExpiresAt());
    }

    public function test_record_deployed_sha_leaves_a_concurrently_replaced_token_alone(): void
    {
        $repo = $this->repository();
        $repo->save($this->deployment());

        $repo->save($this->deployment()->withToken('ghp_replacement'));
        $replacement = $this->store[DeploymentRepository::OPTION]['abc']['token'];

        $repo->recordDeployedSha('abc', 'deadbeef');

        $row = $this->store[DeploymentRepository::OPTION]['abc'];
        $this->assertSame('deadbeef', $row['last_deployed_sha']);
        $this->assertSame($replacement, $row['token']);
        $this->assertSame('ghp_replacement', $repo->find('abc')->token());
    }

    public function test_record_deployed_sha_is_a_no_op_for_an_unknown_id(): void
    {
        $repo = $this->repository();
        $repo->save($this->deployment());
        $before = $this->store[DeploymentRepository::OPTION];

        $repo->recordDeployedSha('nope', 'deadbeef');

        $this->assertSame($before, $this->store[DeploymentRepository::OPTION]);
    }

    public function test_an_unreadable_token_is_flagged_and_its_ciphertext_survives(): void
    {
        $repo = $this->repository();
        $repo->save($this->deployment());
        $this->store[DeploymentRepository::OPTION]['abc']['token'] = 'not-decryptable';

        $found = $repo->find('abc');
        $this->assertTrue($found->isTokenUnreadable());
        $this->assertSame('unreadable', $found->tokenStatus());

        $repo->save($found->withLastDeployedSha('deadbeef'));
        $this->assertSame('not-decryptable', $this->store[DeploymentRepository::OPTION]['abc']['token']);
    }

    public function test_the_stored_row_never_records_the_unreadable_flag(): void
    {
        $repo = $this->repository();
        $repo->save($this->deployment());

        $this->assertFalse($this->store[DeploymentRepository::OPTION]['abc']['token_unreadable']);
    }
}
