<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Infrastructure\Persistence\Eloquent\Concerns\HasPublicId;
use App\Infrastructure\Persistence\Eloquent\Concerns\SoftArchivable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationChannel extends Model
{
    use HasFactory, HasPublicId, SoftArchivable;

    protected $table = 'notification_channels';

    protected $fillable = [
        'public_id',
        'tenant_id',
        'environment_id',
        'name',
        'type',
        'config_json',
        'enabled',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'config_json' => 'array',
            'enabled' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    protected function publicIdPrefix(): string
    {
        return 'nc_';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class, 'channel_id');
    }
}
