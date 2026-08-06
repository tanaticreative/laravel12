<?php

namespace App\Http\Controllers\Api\Booking;

use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\CreateHoldRequest;
use App\Models\Booking\Hold;
use App\Models\Booking\Slot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HoldController extends Controller
{

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateHoldRequest $request, Slot $slot)
    {
        //
    }

    public function confirm(Hold $hold): JsonResponse
    {

    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Hold $hold): JsonResponse
    {
        //
    }
}
