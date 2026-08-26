<?php

namespace Deployward\Http;

use Deployward\Config\Settings;
use Deployward\Config\SettingsRepository;

final class SettingsController
{
    const TEST_SUBJECT = '[Deployward] Test alert';

    /** @var SettingsRepository */
    private $settings;

    public function __construct(SettingsRepository $settings)
    {
        $this->settings = $settings;
    }

    public function get(): ApiResponse
    {
        return ApiResponse::ok($this->present($this->settings->get()));
    }

    public function save(array $params): ApiResponse
    {
        $raw = isset($params['alert_recipients']) ? (string) $params['alert_recipients'] : '';
        foreach (Settings::splitRecipients($raw) as $entry) {
            if (! is_email($entry)) {
                return ApiResponse::error('Not a valid e-mail address: ' . $entry, 422);
            }
        }
        $settings = Settings::fromArray(array(
            'alert_recipients' => $raw,
            'email_on_failure' => isset($params['email_on_failure']) ? $params['email_on_failure'] : false,
            'email_on_success' => isset($params['email_on_success']) ? $params['email_on_success'] : false,
        ));
        $this->settings->save($settings);

        return ApiResponse::ok($this->present($settings));
    }

    public function sendTest(): ApiResponse
    {
        $recipients = $this->settings->recipients();
        if ($recipients === array()) {
            return ApiResponse::error('No recipient to send to: set one below or fix the site admin e-mail.', 422);
        }
        wp_mail(
            $recipients,
            self::TEST_SUBJECT,
            "This is a Deployward test alert.\n\nIf you received it, deploy alerts will reach you too."
        );

        return ApiResponse::ok(array('sent_to' => $recipients));
    }

    private function present(Settings $settings): array
    {
        return array(
            'settings' => $settings->toArray(),
            'recipients' => $this->settings->recipients(),
            'admin_email' => (string) get_option('admin_email', ''),
        );
    }
}
