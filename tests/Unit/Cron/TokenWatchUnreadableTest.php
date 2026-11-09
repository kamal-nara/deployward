<?php

namespace Deployward\Tests\Unit\Cron;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\Config\Deployment;
use Deployward\Config\DeploymentRepository;
use Deployward\Cron\TokenWatch;
use Deployward\Notify\NotifierInterface;
use Deployward\Security\Encryptor;
use Deployward\Security\KeyRing;
use Deployward\Security\TokenReadabilityGuard;
use Deployward\Tests\Support\InMemoryKeyStore;
use PHPUnit\Framework\TestCase;

final class TokenWatchUnreadableTest extends TestCase
{
    const ADMIN_URL = 'https://nara.local/wp-admin/admin.php?page=deployward';

    /** @var array */
    private $options = array();
    /** @var array */
    private $mails = array();
    /** @var InMemoryKeyStore */
    private $keys;
    /** @var string */
    private $previousErrorLog = '';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->options = array();
        $this->mails = array();
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
        Functions\when('wp_date')->alias(function ($format, $timestamp = null) {
            return gmdate($format, $timestamp === null ? 0 : (int) $timestamp);
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

    private function notifier(): NotifierInterface
    {
        return new class($this->mails) implements NotifierInterface {
            /** @var array */
            private $sink;

            public function __construct(array &$sink)
            {
                $this->sink = &$sink;
            }

            public function notify(string $subject, string $body, string $kind = self::KIND_FAILURE): void
            {
                $this->sink[] = array('subject' => $subject, 'body' => $body, 'kind' => $kind);
            }
        };
    }

    private function watch(): TokenWatch
    {
        $repository = $this->repository();
        $notifier = $this->notifier();

        return new TokenWatch(
            $repository,
            $notifier,
            self::ADMIN_URL,
            new TokenReadabilityGuard($repository, $notifier, self::ADMIN_URL)
        );
    }

    private function seedLostToken(): void
    {
        $lost = new Encryptor(KeyRing::fromMaterial(null, '', new InMemoryKeyStore(InMemoryKeyStore::randomKey())));
        $this->options[DeploymentRepository::OPTION] = array('dw_1' => array(
            'id' => 'dw_1',
            'repo' => 'Nara-IT/nara-core',
            'branch' => 'main',
            'visibility' => 'private',
            'target_type' => 'plugin',
            'target_slug' => 'nara-core',
            'token' => $lost->encrypt('ghp_lost'),
            'webhook_secret' => 'whsec',
            'token_last4' => 'lost',
        ));
    }

    private function pasteTheTokenAgain(): void
    {
        $this->repository()->save(Deployment::fromArray(array(
            'id' => 'dw_1',
            'repo' => 'Nara-IT/nara-core',
            'branch' => 'main',
            'visibility' => 'private',
            'target_type' => 'plugin',
            'target_slug' => 'nara-core',
            'token' => 'ghp_pasted_again',
            'webhook_secret' => 'whsec',
        )));
    }

    public function test_two_cron_runs_on_an_unreadable_token_send_one_alert(): void
    {
        $this->seedLostToken();

        $this->watch()->run();
        $this->watch()->run();

        $this->assertCount(1, $this->mails);
        $this->assertSame(NotifierInterface::KIND_ALERT, $this->mails[0]['kind']);
        $this->assertStringContainsString('Nara-IT/nara-core', $this->mails[0]['subject']);
        $this->assertNotSame('', $this->options[DeploymentRepository::OPTION]['dw_1']['token_unreadable_at']);
    }

    public function test_pasting_the_token_again_clears_the_state_and_a_later_loss_alerts_again(): void
    {
        $this->seedLostToken();
        $this->watch()->run();

        $this->pasteTheTokenAgain();
        $this->watch()->run();
        $this->assertSame('', $this->options[DeploymentRepository::OPTION]['dw_1']['token_unreadable_at']);
        $this->assertCount(1, $this->mails);

        $this->keys = new InMemoryKeyStore(InMemoryKeyStore::randomKey());
        $this->watch()->run();

        $this->assertCount(2, $this->mails);
    }

    public function test_the_alert_never_contains_a_token_or_a_key(): void
    {
        $this->seedLostToken();

        $this->watch()->run();

        $text = $this->mails[0]['subject'] . $this->mails[0]['body'];
        $this->assertStringNotContainsString('ghp_', $text);
        $this->assertStringNotContainsString(base64_encode($this->keys->currentKey), $text);
    }

    public function test_an_unreadable_token_skips_the_expiry_warnings(): void
    {
        $this->seedLostToken();
        $this->options[DeploymentRepository::OPTION]['dw_1']['token_expires_at'] = '2020-01-01T00:00:00+00:00';

        $this->watch()->run();

        $this->assertCount(1, $this->mails);
        $this->assertStringNotContainsString('expired', $this->mails[0]['subject']);
    }
}
