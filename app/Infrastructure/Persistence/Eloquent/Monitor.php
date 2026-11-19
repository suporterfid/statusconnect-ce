<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\Monitoring\CheckState;
use App\Domain\Monitoring\MonitorKind;
use App\Infrastructure\Persistence\Eloquent\Concerns\HasPublicId;
use App\Infrastructure\Persistence\Eloquent\Concerns\SoftArchivable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Monitor extends Model
{
    use HasFactory, HasPublicId, SoftArchivable;

    protected $table = 'monitors';

    protected function publicIdPrefix(): string
    {
        return 'mon_';
    }

    /** Only the hash is stored; it must never appear in serialized output either. */
    protected $hidden = ['agent_token_hash'];

    protected $fillable = [
        'public_id',
        'tenant_id',
        'environment_id',
        'name',
        'kind',
        'target',
        'http_method',
        'request_headers_json',
        'request_body',
        'interval_seconds',
        'timeout_ms',
        'confirmation_threshold',
        'recovery_threshold',
        'follow_redirects',
        'verify_tls',
        'egress_profile',
        'check_region',
        'public_safe',
        'enabled',
        'paused_at',
        'current_state',
        'consecutive_failures',
        'consecutive_successes',
        'first_failure_at',
        'second_location_up_until',
        'last_checked_at',
        'next_check_at',
        'last_latency_ms',
        'tls_expires_at',
        'heartbeat_token',
        'heartbeat_grace_seconds',
        'last_ping_at',
        'agent_token_hash',
        'agent_grace_seconds',
        'agent_latency_metric_key',
        'last_ingest_at',
        'mqtt_client_id',
        'mqtt_username',
        'mqtt_password',
        'flapping_since',
        'flap_notification_window_started_at',
        'claim_token',
        'claimed_at',
        'claim_expires_at',
    ];

    protected $casts = [
        'kind' => MonitorKind::class,
        'current_state' => CheckState::class,
        'request_headers_json' => 'array',
        // MQTT broker password (#200): encrypted with APP_KEY, never returned by the API.
        'mqtt_password' => 'encrypted',
        'follow_redirects' => 'boolean',
        'verify_tls' => 'boolean',
        'public_safe' => 'boolean',
        'enabled' => 'boolean',
        'confirmation_threshold' => 'integer',
        'recovery_threshold' => 'integer',
        'consecutive_failures' => 'integer',
        'consecutive_successes' => 'integer',
        'paused_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'first_failure_at' => 'datetime',
        'second_location_up_until' => 'datetime',
        'next_check_at' => 'datetime',
        'tls_expires_at' => 'datetime',
        'last_ping_at' => 'datetime',
        'last_ingest_at' => 'datetime',
        'flapping_since' => 'datetime',
        'flap_notification_window_started_at' => 'datetime',
        'claimed_at' => 'datetime',
        'claim_expires_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class, 'environment_id');
    }

    public function assertions(): HasMany
    {
        return $this->hasMany(MonitorAssertion::class, 'monitor_id')->orderBy('order', 'asc');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class, 'monitor_id');
    }

    public function maintenanceWindows(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(
            MaintenanceWindow::class,
            'maintenance_window_monitors',
            'monitor_id',
            'maintenance_window_id'
        );
    }
}
