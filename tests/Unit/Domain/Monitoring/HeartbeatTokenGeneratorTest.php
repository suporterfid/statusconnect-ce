<?php

namespace Tests\Unit\Domain\Monitoring;

use App\Domain\Monitoring\Services\HeartbeatTokenGenerator;
use PHPUnit\Framework\TestCase;

class HeartbeatTokenGeneratorTest extends TestCase
{
    public function test_generates_unique_high_entropy_tokens(): void
    {
        $generator = new HeartbeatTokenGenerator();
        $token1 = $generator->generate();
        $token2 = $generator->generate();

        $this->assertStringStartsWith('hb_', $token1);
        $this->assertStringStartsWith('hb_', $token2);
        $this->assertNotEquals($token1, $token2);
        $this->assertGreaterThanOrEqual(35, strlen($token1));
    }
}
