<?php

namespace App\Events\Booking;

use Illuminate\Foundation\Events\Dispatchable;
use App\Models\Booking\Hold;
class HoldCancelled
{
    use Dispatchable;

    public function __construct(public readonly Hold $hold) {}
}
