<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Infrastructure\Persistence\Eloquent\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class StatusPageUpdate extends Model
{
    use HasPublicId;

    public const STATUSES = ['investigating', 'identified', 'monitoring', 'resolved', 'scheduled', 'notice'];

    public const IMPACTS = ['none', 'degraded', 'major_outage', 'maintenance'];

    protected $table = 'status_page_updates';

    protected $fillable = [
        'public_id',
        'status_page_id',
        'author_user_id',
        'status',
        'impact',
        'title',
        'body',
        'published_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    protected function publicIdPrefix(): string
    {
        return 'spu_';
    }
}
