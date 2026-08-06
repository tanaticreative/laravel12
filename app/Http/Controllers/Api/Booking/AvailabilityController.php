<?php

namespace App\Http\Controllers\Api\Booking;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AvailabilityController extends Controller
{
   // public function __construct(private readonly SlotService $slots) {}

    /**
     * GET /slots/availability
     */
    public function index(): JsonResponse
    {
    }
}
