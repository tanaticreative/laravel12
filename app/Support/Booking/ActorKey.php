<?php

namespace App\Support\Booking;

use Illuminate\Http\Request;

/**
 * Who a request is acting as.
 *
 * The single definition of caller identity in the application. Hold creation
 * stamps it onto the row and the policy compares against it, so both must
 * derive it the same way — two copies of this rule would drift, and ownership
 * checks would start passing or failing for the wrong reasons.
 */
class ActorKey
{
    public static function for(Request $request): string
    {
        $user = $request->user();

        // Without an auth layer the client address stands in for identity.
        // It is a weak identifier — a shared NAT collapses users together and
        // a roaming client changes address mid-session — so it is a stopgap,
        // not a security boundary. See the README.
        return $user
            ? 'user:'.$user->getAuthIdentifier()
            : 'ip:'.$request->ip();
    }
}
