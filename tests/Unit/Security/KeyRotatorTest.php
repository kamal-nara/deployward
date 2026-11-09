<?php

namespace Deployward\Tests\Unit\Security;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\Config\DeploymentRepository;
use Deployward\Security\Encryptor;
use Deployward\Security\KeyRing;
use Deployward\Security\KeyRotator;
use Deployward\Tests\Support\InMemoryKeyStore;
use PHPUnit\Framework\TestCase;

final class KeyRotatorTest extends TestCase
{
    const SALT_MATERIAL = 'auth-key-material-and-auth-salt-material';

    /** @var array */
    private $options = array();
    /** @var InMemoryKeyStore */
    private $keys;
    /** @var bool */
    private $isStorageFailing = false;
    /** @var string */
    private $previousErrorLog = '';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->options = array();
        $this->keys = new InMemoryKeyStore(InMemoryKeyStore::randomKey());
        $this->isStorageFailing = false;
        $this->previousErrorLog = (string) ini_get('error_log');
        ini_set('error_log', '/dev/null');

        Functions\when('get_option')->alias(function ($key, $default = false) {
            return array_key_exists($key, $this->options) ? $this->options[$key] : $default;
        });
        Functions\when('update_option')->alias(function ($key, $value, $autoload = true) {
            if ($this->isStorageFailing) {
                return false;
            }
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

    private function ring($configured = null): KeyRing
    {
        return KeyRing::fromMaterial($configured, self::SALT_MATERIAL, $this->keys);
    }

    private function repository(): DeploymentRepository
    {
        return new DeploymentRepository(new Encryptor($this->ring()));
    }

    private function rotator(?KeyRing $ring = null): KeyRotator
    {
        return new KeyRotator($ring !== null ? $ring : $this->ring(), $this->keys, $this->repository());
    }

    private function seed(string $id, string $cipher): void
    {
        $rows = isset($this->options[DeploymentRepository::OPTION]) ? $this->options[DeploymentRepository::OPTION] : array();
        $this->options[DeploymentRepository::OPTION] = array_merge($rows, array($id => array(
            'id' => $id,
            'repo' => 'Nara-IT/' . $id,
            'branch' => 'main',
            'visibility' => 'private',
            'target_type' => 'plugin',
            'target_slug' => $id,
            'token' => $cipher,
            'webhook_secret' => 'whsec',
            'token_last4' => 'cret',
        )));
    }

    private function sealWith(string $key, string $plaintext): string
    {
        return (new Encryptor(KeyRing::fromMaterial(null, '', new InMemoryKeyStore($key))))->encrypt($plaintext);
    }

    private function storedToken(string $id): string
    {
        return $this->options[DeploymentRepository::OPTION][$id]['token'];
    }

    private function opensOnlyWith(string $key, string $id): string
    {
        return (new Encryptor(KeyRing::fromMaterial(null, '', new InMemoryKeyStore($key))))
            ->decrypt($this->storedToken($id));
    }

    public function test_every_readable_token_is_re_encrypted_with_a_fresh_key(): void
    {
        $old = $this->keys->currentKey;
        $this->seed('alpha', $this->sealWith($old, 'ghp_alpha'));
        $this->seed('beta', $this->sealWith($old, 'ghp_beta'));

        $result = $this->rotator()->rotate(false);

        $this->assertTrue($result->isOk(), $result->message());
        $this->assertNotSame($old, $this->keys->currentKey);
        $this->assertSame('ghp_alpha', $this->opensOnlyWith($this->keys->currentKey, 'alpha'));
        $this->assertSame('ghp_beta', $this->opensOnlyWith($this->keys->currentKey, 'beta'));
        $this->assertNull($this->keys->previousKey);
        $this->assertSame(2, $result->data()['rotated']);
    }

    public function test_a_token_from_an_older_version_is_carried_over_too(): void
    {
        $saltsKey = sodium_crypto_generichash(self::SALT_MATERIAL, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $this->seed('alpha', $this->sealWith($saltsKey, 'ghp_alpha'));

        $this->rotator()->rotate(false);

        $this->assertSame('ghp_alpha', $this->opensOnlyWith($this->keys->currentKey, 'alpha'));
    }

    public function test_it_refuses_while_any_token_is_unreadable_and_changes_nothing(): void
    {
        $old = $this->keys->currentKey;
        $lost = $this->sealWith(InMemoryKeyStore::randomKey(), 'ghp_lost');
        $good = $this->sealWith($old, 'ghp_good');
        $this->seed('good', $good);
        $this->seed('lost', $lost);

        $result = $this->rotator()->rotate(false);

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('lost', $result->message());
        $this->assertStringContainsString('--force', $result->message());
        $this->assertSame($old, $this->keys->currentKey);
        $this->assertSame($good, $this->storedToken('good'));
        $this->assertSame($lost, $this->storedToken('lost'));
    }

    public function test_force_rotates_the_readable_tokens_and_leaves_unreadable_ones_untouched(): void
    {
        $old = $this->keys->currentKey;
        $lost = $this->sealWith(InMemoryKeyStore::randomKey(), 'ghp_lost');
        $this->seed('good', $this->sealWith($old, 'ghp_good'));
        $this->seed('lost', $lost);

        $result = $this->rotator()->rotate(true);

        $this->assertTrue($result->isOk(), $result->message());
        $this->assertSame('ghp_good', $this->opensOnlyWith($this->keys->currentKey, 'good'));
        $this->assertSame($lost, $this->storedToken('lost'));
        $this->assertSame(1, $result->data()['unreadable']);
    }

    public function test_it_does_nothing_while_the_wp_config_constant_is_the_active_key(): void
    {
        $old = $this->keys->currentKey;
        $this->seed('alpha', $this->sealWith($old, 'ghp_alpha'));

        $result = $this->rotator($this->ring(str_repeat('c', 40)))->rotate(false);

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('DEPLOYWARD_ENCRYPTION_KEY', $result->message());
        $this->assertSame($old, $this->keys->currentKey);
    }

    public function test_a_failed_save_keeps_the_previous_key_so_no_token_is_lost(): void
    {
        $old = $this->keys->currentKey;
        $this->seed('alpha', $this->sealWith($old, 'ghp_alpha'));
        $cipher = $this->storedToken('alpha');
        $this->isStorageFailing = true;
        $this->keys->previousKey = null;

        $result = $this->rotator()->rotate(false);

        $this->assertFalse($result->isOk());
        $this->assertSame($cipher, $this->storedToken('alpha'));
        $this->assertSame($old, $this->keys->previousKey);
        $this->assertSame('ghp_alpha', $this->repository()->find('alpha')->token());
    }

    public function test_an_interrupted_rotation_is_finished_before_the_next_one(): void
    {
        $old = $this->keys->currentKey;
        $this->seed('alpha', $this->sealWith($old, 'ghp_alpha'));
        $this->isStorageFailing = true;
        $this->rotator()->rotate(false);
        $this->assertSame($old, $this->keys->previousKey);

        $blocked = $this->rotator()->rotate(false);
        $this->assertFalse($blocked->isOk());
        $this->assertStringContainsString('earlier rotation', $blocked->message());
        $this->assertSame($old, $this->keys->previousKey);

        $this->isStorageFailing = false;
        $result = $this->rotator()->rotate(false);

        $this->assertTrue($result->isOk(), $result->message());
        $this->assertNull($this->keys->previousKey);
        $this->assertSame('ghp_alpha', $this->opensOnlyWith($this->keys->currentKey, 'alpha'));
    }

    public function test_with_no_deployments_it_still_rotates_the_key(): void
    {
        $old = $this->keys->currentKey;

        $result = $this->rotator()->rotate(false);

        $this->assertTrue($result->isOk());
        $this->assertNotSame($old, $this->keys->currentKey);
        $this->assertSame(0, $result->data()['rotated']);
    }

    public function test_the_result_carries_no_token_or_key_material(): void
    {
        $old = $this->keys->currentKey;
        $this->seed('alpha', $this->sealWith($old, 'ghp_alpha'));

        $result = $this->rotator()->rotate(false);

        $text = $result->message() . json_encode($result->data());
        $this->assertStringNotContainsString('ghp_', $text);
        $this->assertStringNotContainsString(base64_encode($old), $text);
        $this->assertStringNotContainsString(base64_encode($this->keys->currentKey), $text);
    }
}
