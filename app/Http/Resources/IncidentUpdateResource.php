<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Infrastructure\Persistence\Eloquent\IncidentUpdate */
class IncidentUpdateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'status' => $this->status,
            'message' => $this->message,
            'published_at' => \App\Http\Support\ApiDate::format($this->published_at),
        ];
    }
}
