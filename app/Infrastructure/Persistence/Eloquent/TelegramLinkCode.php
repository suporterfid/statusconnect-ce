<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Infrastructure\Persistence\Eloquent\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-time code that links a Telegram chat to an environment (#190).
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $environment_id
 * @property string $code_hash
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $used_at
 * @property int|null $channel_id
 */
class TelegramLinkCode extends Model
{
    use HasPublicId;

    protected $fillable = [
        'tenant_id',
        'environment_id',
        'created_by_user_id',
        'code_hash',
        'expires_at',
        'used_at',
        'channel_id',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    protected function publicIdPrefix(): string
    {
        return 'tlc_';
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(NotificationChannel::class, 'channel_id');
    }

    public function status(): string
    {
        if ($this->used_at !== null) {
            return 'linked';
        }

        return $this->expires_at->isPast() ? 'expired' : 'pending';
    }
}
