<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Infrastructure\Persistence\Eloquent\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MaintenanceWindow extends Model
{
    use HasFactory, HasPublicId;

    protected $table = 'maintenance_windows';

    protected function publicIdPrefix(): string
    {
        return 'mw_';
    }

    protected $fillable = [
        'public_id',
        'tenant_id',
        'environment_id',
        'name',
        'description',
        'starts_at',
        'ends_at',
        'suppress_notifications',
        'recurring_weekly',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'suppress_notifications' => 'boolean',
        'recurring_weekly' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function monitors(): BelongsToMany
    {
        return $this->belongsToMany(
            Monitor::class,
            'maintenance_window_monitors',
            'maintenance_window_id',
            'monitor_id'
        );
    }
}
