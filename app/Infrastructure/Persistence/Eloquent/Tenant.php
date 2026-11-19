<?php

// Ported / mirrored from TaskConnect: app/Infrastructure/Persistence/Eloquent/Tenant.php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Infrastructure\Persistence\Eloquent\Concerns\HasPublicId;
use App\Infrastructure\Persistence\Eloquent\Concerns\SoftArchivable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory, HasPublicId, SoftArchivable;

    protected $fillable = [
        'public_id',
        'name',
        'slug',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
            'approved_at' => 'datetime',
            'signed_up_at' => 'datetime',
            'trial_started_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'trial_reminder_sent_at' => 'datetime',
            'trial_ended_at' => 'datetime',
        ];
    }

    protected function publicIdPrefix(): string
    {
        return 'ten_';
    }

    /**
     * Organizations created by the CLI, the platform console or tests are approved
     * on creation; self-service sign-up clears approved_at afterwards in `approval` mode.
     */
    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant): void {
            if (! $tenant->isDirty('approved_at')) {
                $tenant->approved_at = now();
            }
        });
    }

    /** Sign-up approval (#120): organizations created in `approval` mode wait for a platform admin. */
    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    /** Pro trial (#218): started and not yet ended. */
    public function inTrial(): bool
    {
        return $this->trial_ends_at !== null && $this->trial_ended_at === null;
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    public function environments(): HasMany
    {
        return $this->hasMany(Environment::class);
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function secrets(): HasMany
    {
        return $this->hasMany(Secret::class);
    }
}
