<?php

namespace Deployward\Tests\Unit\Config;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\Config\Deployment;
use Deployward\Config\DeploymentRepository;
use Deployward\Config\DeploymentRepositoryInterface;
use Deployward\Security\Encryptor;
use Deployward\Security\KeyRing;
use Deployward\Tests\Support\InMemoryKeyStore;
use PHPUnit\Framework\TestCase;

final class DeploymentRepositoryMigrationTest extends TestCase
{
    const SALT_MATERIAL = 'auth-key-material-and-auth-salt-material';

    /** @var array */
    private $options = array();
    /** @var int */
    private $writes = 0;
    /** @var int */
    private $reads = 0;
    /** @var ?int */
    private $changeTokenOnRead = null;
    /** @var string */
    private $previousErrorLog = '';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->options = array();
        $this->writes = 0;
        $this->reads = 0;
        $this->changeTokenOnRead = null;
        $this->previousErrorLog = (string) ini_get('error_log');
        ini_set('error_log', '/dev/null');

        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === DeploymentRepository::OPTION) {
                $this->reads++;
                if ($this->changeTokenOnRead === $this->reads) {
                    return $this->withToken($this->options[$key], 'abc', 'someone-elses-fresh-cipher');
                }
            }
            return array_key_exists($key, $this->options) ? $this->options[$key] : $default;
        });
        Functions\when('update_option')->alias(function ($key, $value, $autoload = true) {
            $this->writes++;
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

    private function withToken(array $rows, string $id, string $cipher): array
    {
        return array_merge($rows, array($id => array_merge($rows[$id], array('token' => $cipher))));
    }

    private static function saltsKey(): string
    {
        return sodium_crypto_generichash(self::SALT_MATERIAL, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    private static function sealWith(string $key, string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $key));
    }

    private function repository(InMemoryKeyStore $keys): DeploymentRepository
    {
        return new DeploymentRepository(new Encryptor(KeyRing::fromMaterial(null, self::SALT_MATERIAL, $keys)));
    }

    private function seed(string $id, string $cipher, array $extra = array()): void
    {
        $row = array_merge(array(
            'id' => $id,
            'repo' => 'Nara-IT/nara-core',
            'branch' => 'main',
            'visibility' => 'private',
            'target_type' => 'plugin',
            'target_slug' => 'nara-core',
            'token' => $cipher,
            'webhook_secret' => 'whsec',
            'last_deployed_sha' => 'abc123',
            'token_last4' => 'cret',
        ), $extra);
        $rows = isset($this->options[DeploymentRepository::OPTION]) ? $this->options[DeploymentRepository::OPTION] : array();
        $this->options[DeploymentRepository::OPTION] = array_merge($rows, array($id => $row));
    }

    private function storedToken(string $id): string
    {
        return $this->options[DeploymentRepository::OPTION][$id]['token'];
    }

    public function test_a_token_locked_with_the_salts_is_read_and_re_encrypted_with_the_own_key(): void
    {
        $own = InMemoryKeyStore::randomKey();
        $this->seed('abc', self::sealWith(self::saltsKey(), 'ghp_secret'));

        $found = $this->repository(new InMemoryKeyStore($own))->find('abc');

        $this->assertSame('ghp_secret', $found->token());
        $this->assertFalse($found->isTokenUnreadable());
        $migrated = new Encryptor(KeyRing::fromMaterial(null, '', new InMemoryKeyStore($own)));
        $this->assertSame('ghp_secret', $migrated->decrypt($this->storedToken('abc')));
    }

    public function test_the_migration_creates_the_own_key_when_an_old_install_has_none(): void
    {
        $keys = new InMemoryKeyStore();
        $this->seed('abc', self::sealWith(self::saltsKey(), 'ghp_secret'));

        $this->repository($keys)->all();

        $this->assertSame(1, $keys->creations);
        $reader = new Encryptor(KeyRing::fromMaterial(null, '', $keys));
        $this->assertSame('ghp_secret', $reader->decrypt($this->storedToken('abc')));
    }

    public function test_the_migration_patches_only_the_token_field(): void
    {
        $this->seed('abc', self::sealWith(self::saltsKey(), 'ghp_secret'), array('token_alerted' => '14d'));
        $this->seed('other', 'untouched-cipher', array('repo' => 'Nara-IT/other'));
        $before = $this->options[DeploymentRepository::OPTION];

        $this->repository(new InMemoryKeyStore(InMemoryKeyStore::randomKey()))->all();

        $after = $this->options[DeploymentRepository::OPTION];
        $this->assertSame($before['other'], $after['other']);
        $this->assertNotSame($before['abc']['token'], $after['abc']['token']);
        $this->assertSame(
            array_diff_key($before['abc'], array('token' => 1)),
            array_diff_key($after['abc'], array('token' => 1))
        );
    }

    public function test_the_migration_is_idempotent(): void
    {
        $repository = $this->repository(new InMemoryKeyStore(InMemoryKeyStore::randomKey()));
        $this->seed('abc', self::sealWith(self::saltsKey(), 'ghp_secret'));

        $repository->all();
        $writesAfterFirstRead = $this->writes;
        $cipherAfterFirstRead = $this->storedToken('abc');
        $repository->all();
        $repository->all();

        $this->assertSame(1, $writesAfterFirstRead);
        $this->assertSame($writesAfterFirstRead, $this->writes);
        $this->assertSame($cipherAfterFirstRead, $this->storedToken('abc'));
    }

    public function test_a_failed_encryption_never_changes_or_blanks_the_stored_token(): void
    {
        $keys = new InMemoryKeyStore();
        $keys->isBroken = true;
        $legacyCipher = self::sealWith(self::saltsKey(), 'ghp_secret');
        $this->seed('abc', $legacyCipher);

        $found = $this->repository($keys)->find('abc');

        $this->assertSame('ghp_secret', $found->token());
        $this->assertSame($legacyCipher, $this->storedToken('abc'));
        $this->assertSame(0, $this->writes);
    }

    public function test_a_failed_write_leaves_the_stored_token_alone_and_is_reported(): void
    {
        $legacyCipher = self::sealWith(self::saltsKey(), 'ghp_secret');
        $this->seed('abc', $legacyCipher);
        Functions\when('update_option')->justReturn(false);

        $statuses = $this->repository(new InMemoryKeyStore(InMemoryKeyStore::randomKey()))->tokenStatuses();

        $this->assertSame(DeploymentRepositoryInterface::TOKEN_NEEDS_MIGRATION, $statuses['abc']['state']);
        $this->assertSame($legacyCipher, $this->storedToken('abc'));
    }

    public function test_a_token_replaced_by_someone_else_in_the_meantime_is_not_overwritten(): void
    {
        $this->seed('abc', self::sealWith(self::saltsKey(), 'ghp_secret'));
        $this->changeTokenOnRead = 2;

        $this->repository(new InMemoryKeyStore(InMemoryKeyStore::randomKey()))->all();

        $this->assertSame(0, $this->writes);
    }

    public function test_an_unreadable_token_is_never_modified_and_creates_no_key(): void
    {
        $keys = new InMemoryKeyStore();
        $foreign = self::sealWith(InMemoryKeyStore::randomKey(), 'ghp_elsewhere');
        $this->seed('abc', $foreign);

        $found = $this->repository($keys)->find('abc');

        $this->assertTrue($found->isTokenUnreadable());
        $this->assertSame('', $found->token());
        $this->assertSame($foreign, $this->storedToken('abc'));
        $this->assertSame(0, $this->writes);
        $this->assertSame(0, $keys->creations);
    }

    public function test_token_statuses_names_every_state_without_exposing_secrets(): void
    {
        $own = InMemoryKeyStore::randomKey();
        $reader = new Encryptor(KeyRing::fromMaterial(null, '', new InMemoryKeyStore($own)));
        $this->seed('current', $reader->encrypt('ghp_current'), array('repo' => 'o/current'));
        $this->seed('legacy', self::sealWith(self::saltsKey(), 'ghp_legacy'), array('repo' => 'o/legacy'));
        $this->seed('lost', self::sealWith(InMemoryKeyStore::randomKey(), 'ghp_lost'), array('repo' => 'o/lost'));
        $this->seed('empty', '', array('repo' => 'o/empty', 'visibility' => 'public'));

        $statuses = $this->repository(new InMemoryKeyStore($own))->tokenStatuses();

        $this->assertSame(array(
            'current' => array('repo' => 'o/current', 'state' => 'readable'),
            'legacy' => array('repo' => 'o/legacy', 'state' => 'migrated-now'),
            'lost' => array('repo' => 'o/lost', 'state' => 'unreadable'),
            'empty' => array('repo' => 'o/empty', 'state' => 'none'),
        ), $statuses);
        $this->assertStringNotContainsString('ghp_', json_encode($statuses));
    }

    public function test_a_second_status_run_reports_the_migrated_token_as_readable(): void
    {
        $repository = $this->repository(new InMemoryKeyStore(InMemoryKeyStore::randomKey()));
        $this->seed('abc', self::sealWith(self::saltsKey(), 'ghp_secret'));

        $first = $repository->tokenStatuses();
        $second = $repository->tokenStatuses();

        $this->assertSame('migrated-now', $first['abc']['state']);
        $this->assertSame('readable', $second['abc']['state']);
    }

    public function test_saving_a_new_token_creates_the_own_key_on_first_need(): void
    {
        $keys = new InMemoryKeyStore();
        $repository = $this->repository($keys);

        $repository->save(Deployment::fromArray(array(
            'id' => 'abc',
            'repo' => 'Nara-IT/nara-core',
            'branch' => 'main',
            'visibility' => 'private',
            'target_type' => 'plugin',
            'target_slug' => 'nara-core',
            'token' => 'ghp_secret',
            'webhook_secret' => 'whsec',
        )));

        $this->assertSame(1, $keys->creations);
        $reader = new Encryptor(KeyRing::fromMaterial(null, '', $keys));
        $this->assertSame('ghp_secret', $reader->decrypt($this->storedToken('abc')));
    }
}
