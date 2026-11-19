<?php

namespace Tests\Unit;

use App\Domain\StatusPage\CustomDomain;
use PHPUnit\Framework\TestCase;

class CustomDomainTest extends TestCase
{
    public function test_normalizes_and_validates_hostnames(): void
    {
        $this->assertSame('status.example.com', CustomDomain::normalize(' Status.Example.COM. '));
        $this->assertNull(CustomDomain::normalize('  '));
        $this->assertTrue(CustomDomain::isValid('status.example.com'));
        $this->assertTrue(CustomDomain::isValid('status.acme.com.br'));
        foreach (['localhost', 'https://status.example.com', 'status.example.com/path', '-bad.example.com', 'exa mple.com', '1.2.3.4', 'status..example.com'] as $bad) {
            $this->assertFalse(CustomDomain::isValid($bad), $bad);
        }
    }

    public function test_app_host_and_its_subdomains_are_reserved(): void
    {
        $this->assertTrue(CustomDomain::isReserved('app.statusconnect.net', 'app.statusconnect.net'));
        $this->assertTrue(CustomDomain::isReserved('x.app.statusconnect.net', 'app.statusconnect.net'));
        $this->assertFalse(CustomDomain::isReserved('status.example.com', 'app.statusconnect.net'));
        $this->assertFalse(CustomDomain::isReserved('notapp.statusconnect.net', 'app.statusconnect.net'));
    }
}
