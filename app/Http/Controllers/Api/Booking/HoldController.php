<?php

namespace App\Http\Controllers\Api\Booking;

use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\CreateHoldRequest;
use App\Http\Resources\Booking\HoldResource;
use App\Models\Booking\Hold;
use App\Models\Booking\Slot;
use App\Services\SlotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HoldController extends Controller
{
    public function __construct(private readonly SlotService $slotService) {}

    /**
     * POST /slots/{slot}/hold
     *
     * Requires an Idempotency-Key header. Replaying a key returns the original
     * hold with 200 instead of creating a second one.
     */
    public function store(CreateHoldRequest $request, Slot $slot): JsonResponse
    {
        ['hold' => $hold, 'replayed' => $replayed] = $this->slotService->createHold(
            $slot,
            $request->actorKey(),
            $request->idempotencyKey(),
            $request->payloadFingerprint($slot->id),
        );

        return HoldResource::make($hold)
            ->response()
            ->setStatusCode($replayed ? Response::HTTP_OK : Response::HTTP_CREATED)
            ->header('Idempotent-Replay', $replayed ? 'true' : 'false');
    }

    /**
     * POST /holds/{hold}/confirm
     */
    public function confirm(Hold $hold): JsonResponse
    {
        return HoldResource::make($this->slotService->confirm($hold))->response();
    }

    /**
     * DELETE /holds/{hold}
     */
    public function destroy(Hold $hold): JsonResponse
    {
        return HoldResource::make($this->slotService->cancel($hold))->response();
    }
}
