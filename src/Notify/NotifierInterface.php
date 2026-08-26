<?php

namespace Deployward\Notify;

interface NotifierInterface
{
    const KIND_FAILURE = 'failure';
    const KIND_SUCCESS = 'success';
    const KIND_ALERT = 'alert';

    public function notify(string $subject, string $body, string $kind = self::KIND_FAILURE): void;
}
