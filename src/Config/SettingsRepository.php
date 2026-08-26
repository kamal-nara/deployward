<?php

namespace Deployward\Config;

final class SettingsRepository
{
    const OPTION = 'deployward_settings';

    public function get(): Settings
    {
        $raw = get_option(self::OPTION, array());
        if (! is_array($raw)) {
            return Settings::defaults();
        }

        return Settings::fromArray($raw);
    }

    public function save(Settings $settings): void
    {
        update_option(self::OPTION, $settings->toArray(), false);
    }

    public function recipients(): array
    {
        return $this->resolve($this->get()->alertRecipients());
    }

    public function resolve(string $raw): array
    {
        $valid = array();
        foreach (Settings::splitRecipients($raw) as $entry) {
            if (is_email($entry)) {
                $valid = array_merge($valid, array($entry));
            }
        }
        if ($valid !== array()) {
            return $valid;
        }
        $admin = (string) get_option('admin_email', '');

        return $admin !== '' ? array($admin) : array();
    }
}
