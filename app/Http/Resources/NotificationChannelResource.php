<?php

namespace App\Http\Resources;

use App\Application\Secrets\ResponseSecretMask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A notification channel with its credentials masked (#155): webhook signing
 * secrets, TaskConnect keys and the path of webhook URLs are never returned.
 *
 * @mixin \App\Infrastructure\Persistence\Eloquent\NotificationChannel
 */
class NotificationChannelResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'name' => $this->name,
            'type' => $this->type,
            'config_json' => ResponseSecretMask::config($this->config_json),
            'enabled' => $this->enabled,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
