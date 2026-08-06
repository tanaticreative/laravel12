<?php

namespace App\Exceptions\Booking;

use Symfony\Component\HttpFoundation\Response;
use RuntimeException;
class ApiException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function soldOut(int $slotId): self
    {
        return new self(
            Response::HTTP_CONFLICT,
            'slot_sold_out',
            "Slot {$slotId} has no seats left.",
        );
    }

    /** Expired holds are 410: the resource existed and will not come back. */
    public static function holdExpired(int $holdId): self
    {
        return new self(
            Response::HTTP_GONE,
            'hold_expired',
            "Hold {$holdId} has expired.",
        );
    }

    public static function illegalTransition(int $holdId, string $from, string $to): self
    {
        return new self(
            Response::HTTP_CONFLICT,
            'illegal_transition',
            "Hold {$holdId} cannot go from {$from} to {$to}.",
        );
    }

    /**
     * Same key, different request. Replaying the stored result would answer a
     * question the client did not ask, so this is a client error, not a retry.
     */
    public static function idempotencyKeyReused(string $key): self
    {
        return new self(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'idempotency_key_reused',
            "Idempotency-Key {$key} was already used with a different payload.",
        );
    }

    public function render(): Response
    {
        return response()->json([
            'error' => $this->errorCode,
            'message' => $this->getMessage(),
        ], $this->status);
    }
}
