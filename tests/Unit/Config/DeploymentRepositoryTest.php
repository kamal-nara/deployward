<?php

namespace Deployward\Tests\Unit\Config;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\Config\Deployment;
use Deployward\Config\DeploymentRepository;
use Deployward\Security\Encryptor;
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
        return new DeploymentRepository(new Encryptor(str_repeat('k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
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
