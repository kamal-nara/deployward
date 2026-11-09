<?php

namespace Deployward\Tests\Unit\Cli;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\Cli\KeyCommand;
use Deployward\Config\DeploymentRepository;
use Deployward\Security\Encryptor;
use Deployward\Security\KeyRing;
use Deployward\Security\KeyRotator;
use Deployward\Tests\Support\InMemoryKeyStore;
use PHPUnit\Framework\TestCase;

final class KeyCommandTest extends TestCase
{
    const SALT_MATERIAL = 'auth-key-material-and-auth-salt-material';

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
        \WP_CLI::$errors = array();
        \WP_CLI::$success = array();
        \WP_CLI::$lines = array();

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

    private function command(?KeyRing $ring = null): KeyCommand
    {
        $keyRing = $ring !== null ? $ring : KeyRing::fromMaterial(null, self::SALT_MATERIAL, $this->keys);
        $repository = new DeploymentRepository(new Encryptor($keyRing));

        return new KeyCommand($keyRing, $repository, new KeyRotator($keyRing, $this->keys, $repository));
    }

    private function seed(string $id, string $cipher, string $visibility = 'private'): void
    {
        $rows = isset($this->options[DeploymentRepository::OPTION]) ? $this->options[DeploymentRepository::OPTION] : array();
        $this->options[DeploymentRepository::OPTION] = array_merge($rows, array($id => array(
            'id' => $id,
            'repo' => 'Nara-IT/' . $id,
            'branch' => 'main',
            'visibility' => $visibility,
            'target_type' => 'plugin',
            'target_slug' => $id,
            'token' => $cipher,
            'webhook_secret' => 'whsec',
        )));
    }

    private function sealWith(string $key, string $plaintext): string
    {
        return (new Encryptor(KeyRing::fromMaterial(null, '', new InMemoryKeyStore($key))))->encrypt($plaintext);
    }

    public function test_key_status_names_the_primary_key_the_legacy_key_and_each_token(): void
    {
        $saltsKey = sodium_crypto_generichash(self::SALT_MATERIAL, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $this->seed('current', $this->sealWith($this->keys->currentKey, 'ghp_current'));
        $this->seed('older', $this->sealWith($saltsKey, 'ghp_older'));
        $this->seed('lost', $this->sealWith(InMemoryKeyStore::randomKey(), 'ghp_lost'));
        $this->seed('open', '', 'public');

        $this->command()->status(array(), array());

        $this->assertSame(array(
            'Primary key: own',
            'Legacy keys: salts',
            'current  Nara-IT/current  token=readable',
            'older  Nara-IT/older  token=migrated-now',
            'lost  Nara-IT/lost  token=unreadable',
            'open  Nara-IT/open  token=none',
        ), \WP_CLI::$lines);
    }

    public function test_key_status_reports_the_wp_config_key_and_a_constant_problem(): void
    {
        $short = KeyRing::fromMaterial('short-secret-value', '', $this->keys);
        $this->command($short)->status(array(), array());
        $this->assertContains('Primary key: own', \WP_CLI::$lines);
        $this->assertContains('Legacy keys: none', \WP_CLI::$lines);
        $this->assertTrue($this->anyLineContains('Problem: DEPLOYWARD_ENCRYPTION_KEY'));

        \WP_CLI::$lines = array();
        $this->command(KeyRing::fromMaterial(str_repeat('c', 40), '', new InMemoryKeyStore()))->status(array(), array());
        $this->assertContains('Primary key: wp-config', \WP_CLI::$lines);
    }

    public function test_a_second_key_status_run_shows_the_migrated_token_as_readable(): void
    {
        $saltsKey = sodium_crypto_generichash(self::SALT_MATERIAL, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $this->seed('older', $this->sealWith($saltsKey, 'ghp_older'));

        $this->command()->status(array(), array());
        \WP_CLI::$lines = array();
        $this->command()->status(array(), array());

        $this->assertContains('older  Nara-IT/older  token=readable', \WP_CLI::$lines);
    }

    public function test_key_status_prints_no_token_key_or_ciphertext(): void
    {
        $cipher = $this->sealWith($this->keys->currentKey, 'ghp_current');
        $lost = $this->sealWith(InMemoryKeyStore::randomKey(), 'ghp_lost');
        $this->seed('current', $cipher);
        $this->seed('lost', $lost);
        $own = $this->keys->currentKey;

        $this->command()->status(array(), array());

        $output = implode("\n", \WP_CLI::$lines);
        foreach (array('ghp_', $cipher, $lost, base64_encode($own), bin2hex($own)) as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
    }

    public function test_rotate_key_reports_success_without_secrets(): void
    {
        $this->seed('alpha', $this->sealWith($this->keys->currentKey, 'ghp_alpha'));

        $this->command()->rotate(array(), array());

        $this->assertCount(1, \WP_CLI::$success);
        $this->assertStringContainsString('1 saved token', \WP_CLI::$success[0]);
        $this->assertStringNotContainsString('ghp_', \WP_CLI::$success[0]);
        $this->assertSame(array(), \WP_CLI::$errors);
    }

    public function test_rotate_key_fails_loudly_when_a_token_is_unreadable(): void
    {
        $this->seed('lost', $this->sealWith(InMemoryKeyStore::randomKey(), 'ghp_lost'));
        $old = $this->keys->currentKey;

        try {
            $this->command()->rotate(array(), array());
            $this->fail('rotate-key must stop with an error.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('--force', $e->getMessage());
        }

        $this->assertSame($old, $this->keys->currentKey);
    }

    public function test_rotate_key_with_force_goes_ahead(): void
    {
        $this->seed('lost', $this->sealWith(InMemoryKeyStore::randomKey(), 'ghp_lost'));

        $this->command()->rotate(array(), array('force' => true));

        $this->assertCount(1, \WP_CLI::$success);
        $this->assertStringContainsString('1 unreadable token', \WP_CLI::$success[0]);
    }

    public function test_rotate_key_ignores_a_force_switched_off(): void
    {
        $this->seed('lost', $this->sealWith(InMemoryKeyStore::randomKey(), 'ghp_lost'));
        $old = $this->keys->currentKey;

        foreach (array(false, '0', 'false') as $off) {
            $message = '';
            try {
                $this->command()->rotate(array(), array('force' => $off));
            } catch (\Exception $e) {
                $message = $e->getMessage();
            }
            $this->assertStringContainsString('cannot be read', $message);
        }

        $this->assertSame($old, $this->keys->currentKey);
    }

    private function anyLineContains(string $needle): bool
    {
        foreach (\WP_CLI::$lines as $line) {
            if (strpos($line, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
