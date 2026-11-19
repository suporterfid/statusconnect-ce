<?php

namespace Tests\Unit;

use App\Domain\Shared\MembershipRules;
use App\Domain\Shared\TenantRole;
use PHPUnit\Framework\TestCase;

class MembershipRulesTest extends TestCase
{
    public function test_grant_matrix(): void
    {
        foreach (TenantRole::cases() as $granted) {
            $this->assertTrue(MembershipRules::canGrant(TenantRole::OWNER, $granted));
            $this->assertFalse(MembershipRules::canGrant(TenantRole::VIEWER, $granted));
            $this->assertFalse(MembershipRules::canGrant(null, $granted));
        }
        $this->assertTrue(MembershipRules::canGrant(TenantRole::ADMIN, TenantRole::ADMIN));
        $this->assertTrue(MembershipRules::canGrant(TenantRole::ADMIN, TenantRole::VIEWER));
        $this->assertFalse(MembershipRules::canGrant(TenantRole::ADMIN, TenantRole::OWNER));
    }

    public function test_manage_matrix(): void
    {
        $this->assertTrue(MembershipRules::canManage(TenantRole::OWNER, TenantRole::OWNER));
        $this->assertTrue(MembershipRules::canManage(TenantRole::ADMIN, TenantRole::ADMIN));
        $this->assertFalse(MembershipRules::canManage(TenantRole::ADMIN, TenantRole::OWNER));
        $this->assertFalse(MembershipRules::canManage(TenantRole::VIEWER, TenantRole::VIEWER));
        $this->assertTrue(MembershipRules::canList(TenantRole::VIEWER));
        $this->assertFalse(MembershipRules::canList(null));
    }

    public function test_last_owner_guard(): void
    {
        $this->assertTrue(MembershipRules::removesLastOwner(TenantRole::OWNER, null, 1));
        $this->assertTrue(MembershipRules::removesLastOwner(TenantRole::OWNER, TenantRole::ADMIN, 1));
        $this->assertFalse(MembershipRules::removesLastOwner(TenantRole::OWNER, TenantRole::ADMIN, 2));
        $this->assertFalse(MembershipRules::removesLastOwner(TenantRole::OWNER, TenantRole::OWNER, 1));
        $this->assertFalse(MembershipRules::removesLastOwner(TenantRole::ADMIN, null, 1));
    }
}
