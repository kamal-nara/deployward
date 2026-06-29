<?php

namespace Deployward\Http;

final class RestRoutes
{
    const NAMESPACE = 'deployward/v1';

    /** @var RestController */
    private $controller;

    /** @var WebhookController */
    private $webhook;

    public function __construct(RestController $controller, WebhookController $webhook)
    {
        $this->controller = $controller;
        $this->webhook = $webhook;
    }

    public static function canManage(): bool
    {
        return current_user_can('manage_options');
    }

    public function respond(ApiResponse $response): \WP_REST_Response
    {
        return new \WP_REST_Response($response->data(), $response->status());
    }

    public function register(): void
    {
        $permission = array(__CLASS__, 'canManage');

        register_rest_route(self::NAMESPACE, '/deployments', array(
            array(
                'methods' => 'GET',
                'permission_callback' => $permission,
                'callback' => function () {
                    return $this->respond($this->controller->listDeployments());
                },
            ),
            array(
                'methods' => 'POST',
                'permission_callback' => $permission,
                'callback' => function ($request) {
                    return $this->respond($this->controller->saveDeployment($this->params($request)));
                },
            ),
        ));

        register_rest_route(self::NAMESPACE, '/deployments/(?P<id>[a-zA-Z0-9_-]+)', array(
            array(
                'methods' => 'DELETE',
                'permission_callback' => $permission,
                'callback' => function ($request) {
                    return $this->respond($this->controller->deleteDeployment(sanitize_key($request['id'])));
                },
            ),
        ));

        register_rest_route(self::NAMESPACE, '/deployments/(?P<id>[a-zA-Z0-9_-]+)/deploy', array(
            'methods' => 'POST',
            'permission_callback' => $permission,
            'callback' => function ($request) {
                $force = (bool) $request->get_param('force');
                return $this->respond($this->controller->deployNow(sanitize_key($request['id']), $force));
            },
        ));

        register_rest_route(self::NAMESPACE, '/deployments/(?P<id>[a-zA-Z0-9_-]+)/rollback', array(
            'methods' => 'POST',
            'permission_callback' => $permission,
            'callback' => function ($request) {
                return $this->respond($this->controller->rollback(sanitize_key($request['id'])));
            },
        ));

        register_rest_route(self::NAMESPACE, '/deployments/(?P<id>[a-zA-Z0-9_-]+)/log', array(
            'methods' => 'GET',
            'permission_callback' => $permission,
            'callback' => function ($request) {
                $page = max(1, (int) $request->get_param('page'));
                return $this->respond($this->controller->deploymentLog(sanitize_key($request['id']), $page));
            },
        ));

        register_rest_route(self::NAMESPACE, '/branches', array(
            'methods' => 'POST',
            'permission_callback' => $permission,
            'callback' => function ($request) {
                return $this->respond($this->controller->branches($this->params($request)));
            },
        ));

        register_rest_route(self::NAMESPACE, '/deployments/(?P<id>[a-zA-Z0-9_-]+)/webhook', array(
            'methods' => 'GET',
            'permission_callback' => array(__CLASS__, 'canManage'),
            'callback' => function ($request) {
                return $this->respond($this->controller->webhookInfo(sanitize_key($request['id'])));
            },
        ));

        register_rest_route(self::NAMESPACE, '/webhook/(?P<id>[a-zA-Z0-9_-]+)', array(
            'methods' => 'POST',
            'permission_callback' => '__return_true',
            'callback' => function ($request) {
                return $this->respond($this->webhook->handle(
                    sanitize_key($request['id']),
                    (string) $request->get_body(),
                    $request->get_header('x-hub-signature-256'),
                    $request->get_header('x-github-event')
                ));
            },
        ));
    }

    private function params($request): array
    {
        $raw = $request->get_json_params();
        if (! is_array($raw)) {
            $raw = $request->get_params();
        }
        $clean = array();
        foreach ((array) $raw as $key => $value) {
            $clean[sanitize_key($key)] = is_string($value) ? sanitize_text_field($value) : $value;
        }

        return $clean;
    }
}
