<?php

namespace App\Enums;

enum HoldStatus:string
{
    case Held = 'held';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    /**
     * Allowed transitions. The whole state machine lives here, so adding a
     * state means editing one place instead of hunting through the service.
     *
     *   held ──> confirmed ──> cancelled
     *     └────> cancelled
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Held => [self::Confirmed, self::Cancelled],
            // Cancelling a paid booking is a refund, which the business allows.
            self::Confirmed => [self::Cancelled],
            self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), strict: true);
    }
}
