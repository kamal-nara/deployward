<?php

namespace Deployward\Support;

final class Result
{
    /** @var bool */
    private $ok;
    /** @var bool */
    private $skipped;
    /** @var string */
    private $message;
    /** @var mixed */
    private $data;

    private function __construct(bool $ok, bool $skipped, string $message, $data)
    {
        $this->ok = $ok;
        $this->skipped = $skipped;
        $this->message = $message;
        $this->data = $data;
    }

    public static function ok($data = null, string $message = ''): self
    {
        return new self(true, false, $message, $data);
    }

    public static function fail(string $message, $data = null): self
    {
        return new self(false, false, $message, $data);
    }

    public static function skip(string $message, $data = null): self
    {
        return new self(true, true, $message, $data);
    }

    public function isOk(): bool
    {
        return $this->ok;
    }

    public function isSkipped(): bool
    {
        return $this->skipped;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function data()
    {
        return $this->data;
    }
}
