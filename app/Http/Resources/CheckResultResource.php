<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Infrastructure\Persistence\Eloquent\CheckResult */
class CheckResultResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'state' => $this->state,
            'latency_ms' => $this->latency_ms,
            'status_code' => $this->status_code,
            'failure_reason' => $this->failure_reason,
            'failure_excerpt' => $this->failure_excerpt,
            'confirmation_state' => $this->confirmation_state,
            'confirmation_provider' => $this->confirmation_provider,
            'confirmation_location' => $this->confirmation_location,
            // Where the check ran (#222): null = the StatusConnect server.
            'location' => $this->location,
            // A platform admin voided this result as a measurement failure (#233):
            // it is kept as evidence but does not count toward availability.
            'voided_at' => $this->voided_at,
            'void_reason' => $this->void_reason,
            // #237: on a failure, whether its streak reached the confirmation threshold.
            // An unconfirmed failure is kept here but counts as up for availability.
            'failure_confirmed' => $this->failure_confirmed,
            'checked_at' => $this->checked_at,
            'created_at' => $this->created_at,
        ];
    }
}
