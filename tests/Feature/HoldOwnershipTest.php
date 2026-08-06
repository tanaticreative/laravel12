<?php

namespace Tests\Feature;

use App\Enums\HoldStatus;
use App\Models\Booking\Hold;
use App\Models\Booking\Slot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HoldOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER = '10.0.0.1';
    private const STRANGER = '10.0.0.2';

    private function slot(int $capacity = 5): Slot
    {
        return Slot::create(['name' => 'test', 'capacity' => $capacity, 'remaining' => $capacity]);
    }

    private function as(string $ip): self
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    private function holdAs(string $ip, Slot $slot): int
    {
        return $this->as($ip)
            ->withHeader('Idempotency-Key', Str::uuid()->toString())
            ->postJson("/slots/{$slot->id}/hold")
            ->assertCreated()
            ->json('hold_id');
    }

    #[Test]
    public function the_owner_can_confirm_their_hold(): void
    {
        $id = $this->holdAs(self::OWNER, $this->slot());

        $this->as(self::OWNER)->postJson("/holds/{$id}/confirm")->assertOk();
    }

    #[Test]
    public function the_owner_can_cancel_their_hold(): void
    {
        $id = $this->holdAs(self::OWNER, $this->slot());

        $this->as(self::OWNER)->deleteJson("/holds/{$id}")->assertOk();
    }

    #[Test]
    public function a_stranger_cannot_confirm_someone_elses_hold(): void
    {
        $slot = $this->slot();
        $id = $this->holdAs(self::OWNER, $slot);

        $this->as(self::STRANGER)->postJson("/holds/{$id}/confirm")->assertNotFound();

        // The refusal must be total: no state change, no seat consumed.
        $this->assertSame(HoldStatus::Held, Hold::find($id)->status);
        $this->assertSame($slot->capacity, $slot->fresh()->remaining);
    }

    #[Test]
    public function a_stranger_cannot_cancel_someone_elses_hold(): void
    {
        $id = $this->holdAs(self::OWNER, $this->slot());

        $this->as(self::STRANGER)->deleteJson("/holds/{$id}")->assertNotFound();

        $this->assertSame(HoldStatus::Held, Hold::find($id)->status);
    }

    #[Test]
    public function a_denied_hold_is_indistinguishable_from_a_missing_one(): void
    {
        $id = $this->holdAs(self::OWNER, $this->slot());

        $denied = $this->as(self::STRANGER)->postJson("/holds/{$id}/confirm");
        $missing = $this->as(self::STRANGER)->postJson('/holds/999999/confirm');

        // Hold ids are sequential, so any difference here — status or body —
        // would let an attacker enumerate which holds exist.
        $this->assertSame($missing->status(), $denied->status());
        $this->assertSame($missing->json(), $denied->json());
    }

    #[Test]
    public function ownership_follows_the_authenticated_user_when_there_is_one(): void
    {
        $slot = $this->slot();
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $id = $this->actingAs($owner)
            ->withHeader('Idempotency-Key', Str::uuid()->toString())
            ->postJson("/slots/{$slot->id}/hold")
            ->assertCreated()
            ->json('hold_id');

        $this->assertSame("user:{$owner->id}", Hold::find($id)->actor_key);

        // A different account is a different owner, even from the same address.
        $this->actingAs($other)->postJson("/holds/{$id}/confirm")->assertNotFound();

        $this->actingAs($owner)->postJson("/holds/{$id}/confirm")->assertOk();
    }

    #[Test]
    public function an_authenticated_user_does_not_inherit_a_guests_hold(): void
    {
        $id = $this->holdAs(self::OWNER, $this->slot());

        // The guest hold is keyed by address; logging in changes the actor.
        $this->actingAs(User::factory()->create())
            ->postJson("/holds/{$id}/confirm")
            ->assertNotFound();
    }
}
