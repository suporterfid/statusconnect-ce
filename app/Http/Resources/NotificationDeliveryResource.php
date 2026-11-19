<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Infrastructure\Persistence\Eloquent\NotificationDelivery */
class NotificationDeliveryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'event' => $this->event,
            'status' => $this->status,
            'response_code' => $this->response_code,
            'error_excerpt' => $this->error_excerpt,
            'sent_at' => $this->sent_at,
            'created_at' => $this->created_at,
        ];
    }
}
