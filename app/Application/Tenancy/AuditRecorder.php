<?php

namespace App\Application\Tenancy;

use App\Infrastructure\Persistence\Eloquent\AuditLog;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;

/**
 * Writes user-administration events to audit_logs. Never pass secrets in $summary.
 */
final class AuditRecorder
{
    public function record(?User $actor, string $action, string $resourceType, ?string $resourceId, ?Tenant $tenant = null, array $summary = []): void
    {
        AuditLog::query()->create([
            'tenant_id' => $tenant?->id,
            'actor_user_id' => $actor?->id,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'request_id' => request()?->header('X-Request-Id'),
            'summary_json' => $summary,
        ]);
    }
}
