<?php

namespace App\Http\Resources\Booking;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HoldResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'hold_id' => $this->id,
            'slot_id' => $this->slot_id,
            'status' => $this->status->value,
            'expires_at' => $this->expires_at->toIso8601String(),
        ];
    }
}
