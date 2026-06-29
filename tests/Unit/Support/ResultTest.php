<?php

namespace Deployward\Tests\Unit\Support;

use Deployward\Support\Result;
use PHPUnit\Framework\TestCase;

final class ResultTest extends TestCase
{
    public function test_ok_is_successful_and_not_skipped(): void
    {
        $result = Result::ok('payload', 'done');

        $this->assertTrue($result->isOk());
        $this->assertFalse($result->isSkipped());
        $this->assertSame('payload', $result->data());
        $this->assertSame('done', $result->message());
    }

    public function test_fail_is_not_successful(): void
    {
        $result = Result::fail('boom');

        $this->assertFalse($result->isOk());
        $this->assertSame('boom', $result->message());
    }

    public function test_skip_is_successful_but_skipped(): void
    {
        $result = Result::skip('nothing to do');

        $this->assertTrue($result->isOk());
        $this->assertTrue($result->isSkipped());
    }
}
