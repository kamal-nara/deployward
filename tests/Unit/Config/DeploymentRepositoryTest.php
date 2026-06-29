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

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->store = array();

        Functions\when('get_option')->alias(function ($key, $default = false) {
            return array_key_exists($key, $this->store) ? $this->store[$key] : $default;
        });
        Functions\when('update_option')->alias(function ($key, $value) {
            $this->store[$key] = $value;
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
}
