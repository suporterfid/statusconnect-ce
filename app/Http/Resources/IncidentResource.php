<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Infrastructure\Persistence\Eloquent\Incident */
class IncidentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'manual' => $this->manual,
            'severity' => $this->severity,
            'summary' => $this->summary,
            'started_at' => \App\Http\Support\ApiDate::format($this->started_at),
            'confirmed_at' => \App\Http\Support\ApiDate::format($this->confirmed_at),
            'resolved_at' => \App\Http\Support\ApiDate::format($this->resolved_at),
            'resolved' => $this->resolved_at !== null,
            'duration_seconds' => $this->duration_seconds,
            'updates' => IncidentUpdateResource::collection($this->whenLoaded('updates')),
        ];
    }
}
