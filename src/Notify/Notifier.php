<?php

namespace Deployward\Notify;

use Deployward\Config\SettingsRepository;

final class Notifier implements NotifierInterface
{
    /** @var SettingsRepository */
    private $settings;

    public function __construct(SettingsRepository $settings)
    {
        $this->settings = $settings;
    }

    public function notify(string $subject, string $body, string $kind = self::KIND_FAILURE): void
    {
        if (! $this->shouldSend($kind)) {
            return;
        }
        $recipients = $this->settings->recipients();
        if ($recipients === array()) {
            return;
        }
        wp_mail($recipients, $subject, $body);
    }

    private function shouldSend(string $kind): bool
    {
        if ($kind === self::KIND_ALERT) {
            return true;
        }
        $settings = $this->settings->get();
        if ($kind === self::KIND_SUCCESS) {
            return $settings->shouldEmailOnSuccess();
        }

        return $settings->shouldEmailOnFailure();
    }
}
