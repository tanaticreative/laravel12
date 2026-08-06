<?php

namespace App\Policies\Booking;

use App\Models\Booking\Hold;
use App\Models\User;
use App\Support\Booking\ActorKey;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

class HoldPolicy
{
    public function __construct(private readonly Request $request) {}

    /**
     * The `$user` parameter is nullable so the gate still runs for
     * unauthenticated callers — with no auth layer, every caller is a guest,
     * and a policy that refused to evaluate would deny everything.
     */
    public function confirm(?Authenticatable $user,  $hold): Response
    {
        return $this->owns($hold);
    }

    public function cancel(?Authenticatable $user, Hold $hold): Response
    {
        return $this->owns($hold);
    }

    private function owns(Hold $hold): Response
    {
        if (hash_equals($hold->actor_key, ActorKey::for($this->request))) {
            return Response::allow();
        }

        // Denied as 404, not 403. Hold ids are sequential integers, so a 403
        // would confirm "this hold exists and belongs to someone else" and turn
        // the endpoint into an enumeration oracle. A caller who does not own
        // the hold learns nothing it did not already know.
        return Response::denyAsNotFound();
    }
}
