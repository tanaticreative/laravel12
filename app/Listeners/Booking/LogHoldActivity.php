<?php

namespace App\Listeners\Booking;

use App\Events\Booking\HoldCancelled;
use App\Events\Booking\HoldConfirmed;
use App\Events\Booking\HoldCreated;
use App\Models\Booking\Hold;
use Illuminate\Support\Facades\Log;

/**
 * Structured audit trail for the hold lifecycle.
 *
 * Booking disputes are answered from logs, so every state change is recorded
 * with the same shape and is greppable by event name.
 */
class LogHoldActivity
{
    public function handleCreated(HoldCreated $event): void
    {
        $this->log('hold.created', $event->hold);
    }

    public function handleConfirmed(HoldConfirmed $event): void
    {
        $this->log('hold.confirmed', $event->hold);
    }

    public function handleCancelled(HoldCancelled $event): void
    {
        $this->log('hold.cancelled', $event->hold);
    }

    private function log(string $event, Hold $hold): void
    {
        Log::info($event, [
            'event' => $event,
            'hold_id' => $hold->id,
            'slot_id' => $hold->slot_id,
            'actor' => $hold->actor_key,
            'status' => $hold->status->value,
        ]);
    }
}
