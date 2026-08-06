<?php

namespace App\Http\Controllers\Api\Booking;

use App\Http\Controllers\Controller;
use App\Services\SlotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AvailabilityController extends Controller
{
    public function __construct(private readonly SlotService $slotService) {}

    /**
     * GET /slots/availability
     */
    public function index(): JsonResponse
    {
        return response()->json($this->slotService->availability());
    }
}
