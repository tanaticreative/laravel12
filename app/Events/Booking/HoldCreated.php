<?php

namespace App\Events\Booking;

use App\Models\Booking\Hold;
use Illuminate\Foundation\Events\Dispatchable;

class HoldCreated
{
    use Dispatchable;

    public function __construct(public readonly Hold $hold) {}
}
