<?php

namespace Deployward\Security;

class SignatureVerifier
{
    public function verify(string $payload, ?string $signatureHeader, string $secret): bool
    {
        if ($signatureHeader === null || strpos($signatureHeader, 'sha256=') !== 0) {
            return false;
        }
        $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signatureHeader);
    }
}
