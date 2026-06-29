<?php

namespace Deployward\Tests\Unit\Security;

use Deployward\Security\SignatureVerifier;
use PHPUnit\Framework\TestCase;

final class SignatureVerifierTest extends TestCase
{
    private function sign(string $payload, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $payload, $secret);
    }

    public function test_accepts_a_valid_signature(): void
    {
        $payload = '{"ref":"refs/heads/main"}';
        $secret = 'whsec_example';
        $verifier = new SignatureVerifier();

        $this->assertTrue($verifier->verify($payload, $this->sign($payload, $secret), $secret));
    }

    public function test_rejects_a_tampered_payload(): void
    {
        $secret = 'whsec_example';
        $sig = $this->sign('{"ref":"refs/heads/main"}', $secret);
        $verifier = new SignatureVerifier();

        $this->assertFalse($verifier->verify('{"ref":"refs/heads/evil"}', $sig, $secret));
    }

    public function test_rejects_a_missing_or_malformed_header(): void
    {
        $verifier = new SignatureVerifier();

        $this->assertFalse($verifier->verify('{}', null, 'secret'));
        $this->assertFalse($verifier->verify('{}', '', 'secret'));
        $this->assertFalse($verifier->verify('{}', 'deadbeef', 'secret'));
    }

    public function test_rejects_wrong_secret(): void
    {
        $payload = '{"ref":"refs/heads/main"}';
        $verifier = new SignatureVerifier();

        $this->assertFalse($verifier->verify($payload, $this->sign($payload, 'right'), 'wrong'));
    }
}
