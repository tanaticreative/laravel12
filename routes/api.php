<?php
use App\Http\Controllers\Api\Booking\AvailabilityController;
use App\Http\Controllers\Api\Booking\HoldController;

// Reads are cheap and cached; the write paths are what a client could abuse to
// park every seat in the system, so those get the tighter limit. Both are
// configurable — see config/booking.php.

Route::get('/slots/availability', [AvailabilityController::class, 'index'])
    ->middleware('throttle:'.config('booking.rate_limits.availability'));

Route::middleware('throttle:'.config('booking.rate_limits.writes'))->group(function () {
    Route::post('/slots/{slot}/hold', [HoldController::class, 'store'])
        ->whereNumber('slot');

    // Ownership is enforced by HoldPolicy: knowing a hold id is not the same
    // as being allowed to act on it.
    Route::post('/holds/{hold}/confirm', [HoldController::class, 'confirm'])
        ->whereNumber('hold')
        ->can('confirm', 'hold');

    Route::delete('/holds/{hold}', [HoldController::class, 'destroy'])
        ->whereNumber('hold')
        ->can('cancel', 'hold');
});
