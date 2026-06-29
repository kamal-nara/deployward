<?php

namespace Deployward\Tests\Unit\Http;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\Config\DeploymentRepositoryInterface;
use Deployward\Deploy\DeployerInterface;
use Deployward\Deploy\DeploySchedulerInterface;
use Deployward\GitHub\GitHubClientInterface;
use Deployward\Http\ApiResponse;
use Deployward\Http\RestController;
use Deployward\Http\RestRoutes;
use Deployward\Http\WebhookController;
use Deployward\Log\DeployLogInterface;
use Deployward\Security\SignatureVerifierInterface;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class RestRoutesTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        if (! class_exists('WP_REST_Response')) {
            eval('class WP_REST_Response { public $data; public $status; public function __construct($d = null, $s = 200) { $this->data = $d; $this->status = $s; } }');
        }
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_can_manage_reflects_capability(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        $this->assertFalse(RestRoutes::canManage());

        Functions\when('current_user_can')->justReturn(true);
        $this->assertTrue(RestRoutes::canManage());
    }

    public function test_respond_maps_apiresponse_to_wp_rest_response(): void
    {
        $routes = new RestRoutes(
            $this->makeRestController(),
            $this->makeWebhookController()
        );
        $wp = $routes->respond(ApiResponse::error('nope', 404));

        $this->assertSame(404, $wp->status);
        $this->assertSame('nope', $wp->data['error']);
    }

    public function test_constructor_accepts_a_webhook_controller(): void
    {
        $routes = new RestRoutes(
            $this->makeRestController(),
            $this->makeWebhookController()
        );

        $this->assertInstanceOf(RestRoutes::class, $routes);
    }

    public function test_register_adds_the_public_webhook_route(): void
    {
        $captured = array();
        Functions\when('register_rest_route')->alias(function ($ns, $route, $args) use (&$captured) {
            $captured[] = array('route' => $route, 'args' => $args);
        });
        Functions\when('current_user_can')->justReturn(true);

        $routes = new RestRoutes(
            $this->makeRestController(),
            $this->makeWebhookController()
        );
        $routes->register();

        $webhook = null;
        foreach ($captured as $r) {
            if (strpos($r['route'], '/webhook/') === 0) {
                $webhook = $r;
            }
        }
        $this->assertNotNull($webhook, 'webhook route registered');
        $perm = isset($webhook['args']['permission_callback']) ? $webhook['args']['permission_callback'] : (isset($webhook['args'][0]['permission_callback']) ? $webhook['args'][0]['permission_callback'] : null);
        $this->assertTrue($perm === '__return_true' || (is_callable($perm) && $perm() === true));
    }

    private function makeRestController(): RestController
    {
        return new RestController(
            Mockery::mock(DeploymentRepositoryInterface::class),
            Mockery::mock(DeployerInterface::class),
            Mockery::mock(DeployLogInterface::class),
            Mockery::mock(GitHubClientInterface::class)
        );
    }

    private function makeWebhookController(): WebhookController
    {
        return new WebhookController(
            Mockery::mock(DeploymentRepositoryInterface::class),
            Mockery::mock(SignatureVerifierInterface::class),
            Mockery::mock(DeploySchedulerInterface::class)
        );
    }
}
