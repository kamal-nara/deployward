<?php

namespace Deployward\Config;

final class Settings
{
    /** @var string */
    private $alertRecipients;
    /** @var bool */
    private $emailOnFailure;
    /** @var bool */
    private $emailOnSuccess;

    public function __construct(
        string $alertRecipients = '',
        bool $emailOnFailure = true,
        bool $emailOnSuccess = true
    ) {
        $this->alertRecipients = $alertRecipients;
        $this->emailOnFailure = $emailOnFailure;
        $this->emailOnSuccess = $emailOnSuccess;
    }

    public static function defaults(): self
    {
        return new self('', true, true);
    }

    public static function fromArray(array $data): self
    {
        $defaults = self::defaults();

        return new self(
            isset($data['alert_recipients']) ? trim((string) $data['alert_recipients']) : $defaults->alertRecipients(),
            isset($data['email_on_failure'])
                ? filter_var($data['email_on_failure'], FILTER_VALIDATE_BOOLEAN)
                : $defaults->shouldEmailOnFailure(),
            isset($data['email_on_success'])
                ? filter_var($data['email_on_success'], FILTER_VALIDATE_BOOLEAN)
                : $defaults->shouldEmailOnSuccess()
        );
    }

    public function toArray(): array
    {
        return array(
            'alert_recipients' => $this->alertRecipients,
            'email_on_failure' => $this->emailOnFailure,
            'email_on_success' => $this->emailOnSuccess,
        );
    }

    public static function splitRecipients(string $raw): array
    {
        $parts = array();
        foreach (explode(',', $raw) as $entry) {
            $trimmed = trim($entry);
            if ($trimmed !== '' && ! in_array($trimmed, $parts, true)) {
                $parts = array_merge($parts, array($trimmed));
            }
        }

        return $parts;
    }

    public function alertRecipients(): string
    {
        return $this->alertRecipients;
    }

    public function shouldEmailOnFailure(): bool
    {
        return $this->emailOnFailure;
    }

    public function shouldEmailOnSuccess(): bool
    {
        return $this->emailOnSuccess;
    }
}
