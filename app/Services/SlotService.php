<?php

namespace App\Services;

use App\Enums\HoldStatus;
use App\Events\Booking\HoldCancelled;
use App\Events\Booking\HoldConfirmed;
use App\Events\Booking\HoldCreated;
use App\Exceptions\Booking\ApiException;
use App\Models\Booking\Hold;
use App\Models\Booking\Slot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SlotService
{
    public function __construct(private readonly AvailabilityCacheService $cacheService) {}

    /**
     * Availability for every slot, cached for 5-15 seconds.
     *
     * @return list<array{slot_id:int, capacity:int, remaining:int}>
     */
    public function availability(): array
    {
        return $this->cacheService->remember(fn () => $this->readAvailability());
    }

    /**
     * Create a hold, or replay the result of the request that first used this
     * Idempotency-Key.
     *
     * The bound `$slot` was read before this transaction opened, so it is used
     * only to identify the row — every decision below is made on the copy read
     * under a lock inside the transaction.
     *
     * @return array{hold: Hold, replayed: bool}
     */
    public function createHold(Slot $slot, string $actorKey, string $idempotencyKey, string $fingerprint): array
    {
        // Fast path: the key is already known, no need to touch the slot.
        if ($existing = $this->findByKey($actorKey, $idempotencyKey)) {
            return $this->replay($existing, $fingerprint);
        }

        try {
            $hold = DB::transaction(function () use ($slot, $actorKey, $idempotencyKey, $fingerprint) {
                // Lock order: slot, then hold. Every write path in this service
                // takes the same order, which is what keeps concurrent
                // confirmations and creations from deadlocking each other.
                $locked = Slot::whereKey($slot->id)->lockForUpdate()->firstOrFail();

                // Correct under REPEATABLE READ: the locking read above pins the
                // slot, so no competing hold can be inserted between this count
                // and our own insert.
                $activeHolds = Hold::where('slot_id', $locked->id)->active()->count();

                if ($locked->remaining - $activeHolds <= 0) {
                    throw ApiException::soldOut($locked->id);
                }

                return Hold::create([
                    'slot_id' => $locked->id,
                    'actor_key' => $actorKey,
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $fingerprint,
                    'status' => HoldStatus::Held,
                    'expires_at' => now()->addMinutes(Hold::TTL_MINUTES),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Two requests carried the same key concurrently and both got past
            // the fast path. The other one won; return its hold.
            $winner = $this->findByKey($actorKey, $idempotencyKey)
                ?? throw new ModelNotFoundException('Idempotent hold vanished after a unique violation.');

            return $this->replay($winner, $fingerprint);
        }

        // Only after the transaction committed: a rollback must not leave the
        // cache cleared against unchanged data, and events must not fire for
        // work that never happened.
        $this->cacheService->invalidate();
        HoldCreated::dispatch($hold);

        return ['hold' => $hold, 'replayed' => false];
    }

    /**
     * Confirm a hold, decrementing the slot atomically.
     */
    public function confirm(Hold $bound): Hold
    {
        [$hold, $changed] = DB::transaction(function () use ($bound) {
            [$slot, $hold] = $this->lockSlotThenHold($bound);

            // Confirming twice is a no-op rather than an error, so a retried
            // request does not fail after the first attempt already succeeded.
            if ($hold->status === HoldStatus::Confirmed) {
                return [$hold, false];
            }

            if (! $hold->canTransitionTo(HoldStatus::Confirmed)) {
                throw ApiException::illegalTransition(
                    $hold->id,
                    $hold->status->value,
                    HoldStatus::Confirmed->value,
                );
            }

            if ($hold->isExpired()) {
                throw ApiException::holdExpired($hold->id);
            }

            // The oversell guard. `remaining > 0` lives in the WHERE clause, so
            // the check and the decrement are one atomic statement: a loser
            // updates zero rows instead of driving the counter negative.
            $affected = Slot::whereKey($slot->id)
                ->where('remaining', '>', 0)
                ->decrement('remaining');

            if ($affected === 0) {
                throw ApiException::soldOut($slot->id);
            }

            $hold->forceFill([
                'status' => HoldStatus::Confirmed,
                'confirmed_at' => now(),
            ])->save();

            return [$hold, true];
        });

        if ($changed) {
            $this->cacheService->invalidate();
            HoldConfirmed::dispatch($hold);
        }

        return $hold;
    }

    /**
     * Cancel a hold and return its seat to the pool.
     */
    public function cancel(Hold $bound): Hold
    {
        [$hold, $changed] = DB::transaction(function () use ($bound) {
            [$slot, $hold] = $this->lockSlotThenHold($bound);

            if ($hold->status === HoldStatus::Cancelled) {
                return [$hold, false];
            }

            if ($hold->status === HoldStatus::Confirmed) {
                // Give the seat back, never above capacity.
                $restored = Slot::whereKey($slot->id)
                    ->whereColumn('remaining', '<', 'capacity')
                    ->increment('remaining');

                if ($restored === 0) {
                    // A confirmed hold implies a seat was taken, so the counter
                    // must have had room to grow. Reaching here means slot and
                    // hold rows disagree — the cancellation still stands, but
                    // this is a data-integrity problem, not routine.
                    Log::warning('hold.seat_not_restored', [
                        'hold_id' => $hold->id,
                        'slot_id' => $slot->id,
                        'remaining' => $slot->remaining,
                        'capacity' => $slot->capacity,
                    ]);
                }
            }

            // A hold that was never confirmed did not decrement `remaining`;
            // it stops reserving a seat the moment its status changes.

            $hold->forceFill([
                'status' => HoldStatus::Cancelled,
                'cancelled_at' => now(),
            ])->save();

            return [$hold, true];
        });

        if ($changed) {
            $this->cacheService->invalidate();
            HoldCancelled::dispatch($hold);
        }

        return $hold;
    }

    /**
     * Take both row locks in the one order used everywhere: parent slot first,
     * then the hold. Mixing the order across code paths is the classic way to
     * deadlock two transactions that touch the same pair of rows.
     *
     * @return array{0: Slot, 1: Hold}
     */
    private function lockSlotThenHold(Hold $bound): array
    {
        // `slot_id` never changes once a hold exists, so the bound copy is safe
        // to route on — it only decides *which* slot row to lock. Everything
        // the decisions depend on (status, expiry, remaining) is re-read below
        // under a lock, because the bound copy predates this transaction.
        $slot = Slot::whereKey($bound->slot_id)->lockForUpdate()->firstOrFail();
        $hold = Hold::whereKey($bound->id)->lockForUpdate()->firstOrFail();

        return [$slot, $hold];
    }

    private function findByKey(string $actorKey, string $idempotencyKey): ?Hold
    {
        return Hold::where('actor_key', $actorKey)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    /**
     * The uncached truth.
     *
     * Advertised seats subtract live holds as well as confirmations, so a seat
     * someone is holding is never offered to somebody else.
     *
     * @return list<array{slot_id:int, capacity:int, remaining:int}>
     */
    private function readAvailability(): array
    {
        return Slot::query()
            ->withCount(['holds as active_holds_count' => fn (Builder $query) => $query->active()])
            ->orderBy('id')
            ->get()
            ->map(fn (Slot $slot) => [
                'slot_id' => $slot->id,
                'capacity' => $slot->capacity,
                'remaining' => max(0, $slot->remaining - $slot->active_holds_count),
            ])
            ->all();
    }

    /**
     * @return array{hold: Hold, replayed: bool}
     */
    private function replay(Hold $hold, string $fingerprint): array
    {
        // Same key, different request: the stored result answers a question
        // this caller did not ask, so returning it would be a lie.
        if (! hash_equals($hold->request_hash, $fingerprint)) {
            throw ApiException::idempotencyKeyReused($hold->idempotency_key);
        }

        return ['hold' => $hold, 'replayed' => true];
    }
}
