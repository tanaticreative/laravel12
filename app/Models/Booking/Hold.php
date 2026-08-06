<?php

namespace App\Models\Booking;

use App\Enums\HoldStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Hold extends Model
{
    use HasFactory;

    /** How long a hold reserves a seat before it stops counting. */
    public const TTL_MINUTES = 5;

    protected $fillable = [
        'slot_id',
        'actor_key',
        'idempotency_key',
        'request_hash',
        'status',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => HoldStatus::class,
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(Slot::class);
    }

    /**
     * Holds that still reserve a seat: created, not yet confirmed or
     * cancelled, and not past their TTL. Expired holds are ignored by this
     * scope rather than deleted, which is why no background cleanup is needed.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', HoldStatus::Held)
            ->where('expires_at', '>', now());
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function canTransitionTo(HoldStatus $target): bool
    {
        return $this->status->canTransitionTo($target);
    }

    /**
     * A hold can only be confirmed while it is held and unexpired. Expiry is
     * deliberately not part of `canTransitionTo`: the caller must be able to
     * tell "wrong state" (409) from "too late" (410).
     */
    public function canConfirm(): bool
    {
        return $this->canTransitionTo(HoldStatus::Confirmed) && ! $this->isExpired();
    }

    public function canCancel(): bool
    {
        return $this->canTransitionTo(HoldStatus::Cancelled);
    }

    /**
     * Fingerprint of the request that created the hold, used to reject a key
     * replayed with a different payload.
     */
    public static function fingerprint(array $payload): string
    {
        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
