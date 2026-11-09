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

final class DeploymentRepositoryUnreadableStateTest extends TestCase
{
    const STAMP = '2026-11-06T10:00:00+00:00';

    /** @var array */
    private $options = array();
    /** @var InMemoryKeyStore */
    private $keys;
    /** @var string */
    private $previousErrorLog = '';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->options = array();
        $this->keys = new InMemoryKeyStore(InMemoryKeyStore::randomKey());
        $this->previousErrorLog = (string) ini_get('error_log');
        ini_set('error_log', '/dev/null');

        Functions\when('get_option')->alias(function ($key, $default = false) {
            return array_key_exists($key, $this->options) ? $this->options[$key] : $default;
        });
        Functions\when('update_option')->alias(function ($key, $value, $autoload = true) {
            $this->options[$key] = $value;
            return true;
        });
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);
        Monkey\tearDown();
        parent::tearDown();
    }

    private function repository(): DeploymentRepository
    {
        return new DeploymentRepository(new Encryptor(KeyRing::fromMaterial(null, '', $this->keys)));
    }

    private function seed(string $cipher, string $unreadableAt): void
    {
        $this->options[DeploymentRepository::OPTION] = array('abc' => array(
            'id' => 'abc',
            'repo' => 'Nara-IT/nara-core',
            'branch' => 'main',
            'visibility' => 'private',
            'target_type' => 'plugin',
            'target_slug' => 'nara-core',
            'token' => $cipher,
            'webhook_secret' => 'whsec',
            'token_last4' => 'cret',
            'token_unreadable_at' => $unreadableAt,
        ));
    }

    private function lostCipher(): string
    {
        return (new Encryptor(KeyRing::fromMaterial(null, '', new InMemoryKeyStore(InMemoryKeyStore::randomKey()))))
            ->encrypt('ghp_lost');
    }

    private function submitted(string $token): Deployment
    {
        return Deployment::fromArray(array(
            'id' => 'abc',
            'repo' => 'Nara-IT/nara-core',
            'branch' => 'main',
            'visibility' => 'private',
            'target_type' => 'plugin',
            'target_slug' => 'nara-core',
            'token' => $token,
            'webhook_secret' => 'whsec',
        ));
    }

    private function storedRow(): array
    {
        return $this->options[DeploymentRepository::OPTION]['abc'];
    }

    public function test_the_recorded_state_is_read_back_on_the_deployment(): void
    {
        $this->seed($this->lostCipher(), self::STAMP);

        $found = $this->repository()->find('abc');

        $this->assertSame(self::STAMP, $found->tokenUnreadableAt());
        $this->assertTrue($found->isTokenUnreadable());
    }

    public function test_record_token_state_stores_and_clears_the_unreadable_timestamp(): void
    {
        $this->seed($this->lostCipher(), '');
        $repository = $this->repository();

        $repository->recordTokenState('abc', array('token_unreadable_at' => self::STAMP));
        $this->assertSame(self::STAMP, $this->storedRow()['token_unreadable_at']);

        $repository->recordTokenState('abc', array('token_unreadable_at' => ''));
        $this->assertSame('', $this->storedRow()['token_unreadable_at']);
    }

    public function test_saving_a_new_readable_token_clears_the_state(): void
    {
        $this->seed($this->lostCipher(), self::STAMP);

        $this->repository()->save($this->submitted('ghp_pasted_again'));

        $this->assertSame('', $this->storedRow()['token_unreadable_at']);
        $this->assertSame('ghp_pasted_again', $this->repository()->find('abc')->token());
    }

    public function test_saving_other_fields_keeps_the_state_while_the_token_stays_unreadable(): void
    {
        $lost = $this->lostCipher();
        $this->seed($lost, self::STAMP);

        $this->repository()->save($this->submitted(''));

        $this->assertSame(self::STAMP, $this->storedRow()['token_unreadable_at']);
        $this->assertSame($lost, $this->storedRow()['token']);
    }

    public function test_saving_other_fields_clears_a_stale_state_when_the_token_is_readable(): void
    {
        $repository = $this->repository();
        $readable = (new Encryptor(KeyRing::fromMaterial(null, '', $this->keys)))->encrypt('ghp_ok');
        $this->seed($readable, self::STAMP);

        $repository->save($this->submitted(''));

        $this->assertSame('', $this->storedRow()['token_unreadable_at']);
    }

    public function test_clearing_the_token_clears_the_state(): void
    {
        $this->seed($this->lostCipher(), self::STAMP);

        $this->repository()->save($this->submitted(''), true);

        $this->assertSame('', $this->storedRow()['token_unreadable_at']);
    }
}
