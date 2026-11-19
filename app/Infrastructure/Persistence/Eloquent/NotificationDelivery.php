<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Infrastructure\Persistence\Eloquent\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationDelivery extends Model
{
    use HasFactory, HasPublicId;

    protected $table = 'notification_deliveries';

    protected $fillable = [
        'public_id',
        'tenant_id',
        'incident_id',
        'channel_id',
        'event',
        'status',
        'response_code',
        'error_excerpt',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'response_code' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    protected function publicIdPrefix(): string
    {
        return 'nd_';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(NotificationChannel::class, 'channel_id');
    }
}
