<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Infrastructure\Persistence\Eloquent\MaintenanceWindow */
class MaintenanceWindowResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'name' => $this->name,
            'description' => $this->description,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'suppress_notifications' => $this->suppress_notifications,
            'recurring_weekly' => $this->recurring_weekly,
            'monitors' => $this->whenLoaded('monitors', fn () => $this->monitors->map(fn ($m) => [
                'public_id' => $m->public_id,
                'name' => $m->name,
            ])->values()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
