<?php

namespace Tests\Feature;

use App\Enums\HoldStatus;
use App\Events\Booking\HoldConfirmed;
use App\Events\Booking\HoldCreated;
use App\Models\Booking\Hold;
use App\Models\Booking\Slot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HoldTest extends TestCase
{
    use RefreshDatabase;

    private function slot(int $capacity = 5, ?int $remaining = null): Slot
    {
        return Slot::create([
            'name' => 'test',
            'capacity' => $capacity,
            'remaining' => $remaining ?? $capacity,
        ]);
    }

    private function hold(Slot $slot, string $key = null)
    {
        return $this->withHeader('Idempotency-Key', $key ?? Str::uuid()->toString())
            ->postJson("/slots/{$slot->id}/hold");
    }

    #[Test]
    public function it_creates_a_hold(): void
    {
        $slot = $this->slot();

        $this->hold($slot)
            ->assertCreated()
            ->assertHeader('Idempotent-Replay', 'false')
            ->assertJson(['slot_id' => $slot->id, 'status' => 'held']);

        $this->assertDatabaseCount('holds', 1);
    }

    #[Test]
    public function it_returns_the_same_hold_for_the_same_key(): void
    {
        $slot = $this->slot();
        $key = Str::uuid()->toString();

        $first = $this->hold($slot, $key)->assertCreated();

        $this->hold($slot, $key)
            ->assertOk()
            ->assertHeader('Idempotent-Replay', 'true')
            ->assertJson(['hold_id' => $first->json('hold_id')]);

        // The point of idempotency: the retry must not consume a second seat.
        $this->assertDatabaseCount('holds', 1);
    }

    #[Test]
    public function it_rejects_a_key_replayed_with_a_different_payload(): void
    {
        $key = Str::uuid()->toString();
        $this->hold($this->slot(), $key)->assertCreated();

        $this->hold($this->slot(), $key)
            ->assertStatus(422)
            ->assertJson(['error' => 'idempotency_key_reused']);
    }

    #[Test]
    public function idempotency_keys_are_scoped_to_the_actor(): void
    {
        $slot = $this->slot();
        $key = Str::uuid()->toString();

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->hold($slot, $key)->assertCreated();

        // A different caller reusing the same key gets their own hold rather
        // than someone else's — the key is not a capability.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->hold($slot, $key)->assertCreated();

        $this->assertDatabaseCount('holds', 2);
    }

    #[Test]
    public function it_requires_a_valid_idempotency_key(): void
    {
        $slot = $this->slot();

        $this->postJson("/slots/{$slot->id}/hold")->assertStatus(422);

        $this->withHeader('Idempotency-Key', 'nope')
            ->postJson("/slots/{$slot->id}/hold")
            ->assertStatus(422);
    }

    #[Test]
    public function it_returns_409_when_the_slot_is_sold_out(): void
    {
        $slot = $this->slot(capacity: 1, remaining: 0);

        $this->hold($slot)
            ->assertStatus(409)
            ->assertJson(['error' => 'slot_sold_out']);
    }

    #[Test]
    public function live_holds_reserve_seats_against_further_holds(): void
    {
        $slot = $this->slot(capacity: 2);

        $this->hold($slot)->assertCreated();
        $this->hold($slot)->assertCreated();

        // Both seats are spoken for even though nothing is confirmed yet.
        $this->hold($slot)->assertStatus(409);
    }

    #[Test]
    public function expired_holds_stop_reserving_seats(): void
    {
        $slot = $this->slot(capacity: 1);
        $this->hold($slot)->assertCreated();

        Hold::query()->update(['expires_at' => now()->subMinute()]);

        $this->hold($slot)->assertCreated();
    }

    #[Test]
    public function it_confirms_a_hold_and_decrements_the_slot(): void
    {
        $slot = $this->slot(capacity: 3);
        $id = $this->hold($slot)->json('hold_id');

        $this->postJson("/holds/{$id}/confirm")
            ->assertOk()
            ->assertJson(['status' => 'confirmed']);

        $this->assertSame(2, $slot->fresh()->remaining);
    }

    #[Test]
    public function confirming_twice_does_not_decrement_twice(): void
    {
        $slot = $this->slot(capacity: 3);
        $id = $this->hold($slot)->json('hold_id');

        $this->postJson("/holds/{$id}/confirm")->assertOk();
        $this->postJson("/holds/{$id}/confirm")->assertOk();

        $this->assertSame(2, $slot->fresh()->remaining);
    }

    #[Test]
    public function it_returns_410_when_confirming_an_expired_hold(): void
    {
        $slot = $this->slot();
        $id = $this->hold($slot)->json('hold_id');

        Hold::whereKey($id)->update(['expires_at' => now()->subMinute()]);

        $this->postJson("/holds/{$id}/confirm")
            ->assertStatus(410)
            ->assertJson(['error' => 'hold_expired']);

        $this->assertSame($slot->capacity, $slot->fresh()->remaining);
    }

    #[Test]
    public function it_rejects_illegal_state_transitions(): void
    {
        $slot = $this->slot();
        $id = $this->hold($slot)->json('hold_id');

        $this->deleteJson("/holds/{$id}")->assertOk();

        $this->postJson("/holds/{$id}/confirm")
            ->assertStatus(409)
            ->assertJson(['error' => 'illegal_transition']);
    }

    #[Test]
    public function cancelling_a_held_hold_frees_the_seat(): void
    {
        $slot = $this->slot(capacity: 1);
        $id = $this->hold($slot)->json('hold_id');

        $this->deleteJson("/holds/{$id}")->assertOk()->assertJson(['status' => 'cancelled']);

        // Never confirmed, so the counter was never touched.
        $this->assertSame(1, $slot->fresh()->remaining);
        $this->hold($slot)->assertCreated();
    }

    #[Test]
    public function cancelling_a_confirmed_hold_returns_the_seat(): void
    {
        $slot = $this->slot(capacity: 1);
        $id = $this->hold($slot)->json('hold_id');
        $this->postJson("/holds/{$id}/confirm")->assertOk();
        $this->assertSame(0, $slot->fresh()->remaining);

        $this->deleteJson("/holds/{$id}")->assertOk();

        $this->assertSame(1, $slot->fresh()->remaining);
    }

    #[Test]
    public function cancelling_twice_does_not_return_two_seats(): void
    {
        $slot = $this->slot(capacity: 1);
        $id = $this->hold($slot)->json('hold_id');
        $this->postJson("/holds/{$id}/confirm")->assertOk();

        $this->deleteJson("/holds/{$id}")->assertOk();
        $this->deleteJson("/holds/{$id}")->assertOk();

        $this->assertSame(1, $slot->fresh()->remaining);
    }

    #[Test]
    public function it_returns_404_for_unknown_slots_and_holds(): void
    {
        $this->withHeader('Idempotency-Key', Str::uuid()->toString())
            ->postJson('/slots/9999/hold')
            ->assertNotFound();

        $this->postJson('/holds/9999/confirm')->assertNotFound();
        $this->deleteJson('/holds/9999')->assertNotFound();
    }

    #[Test]
    public function binding_resolves_before_validation(): void
    {
        // SubstituteBindings runs ahead of the FormRequest, so a request that
        // is wrong in both ways is answered "no such slot" rather than
        // "your header is malformed" — the resource question comes first.
        $this->postJson('/slots/9999/hold')->assertNotFound();
    }

    #[Test]
    public function it_rejects_non_numeric_route_ids(): void
    {
        $this->postJson('/holds/abc/confirm')->assertNotFound();
        $this->withHeader('Idempotency-Key', Str::uuid()->toString())
            ->postJson('/slots/abc/hold')
            ->assertNotFound();
    }

    #[Test]
    public function errors_stay_json_without_an_accept_header(): void
    {
        $slot = $this->slot();

        // A client that omits Accept would otherwise be redirected (302) on a
        // validation failure and handed an HTML page on a 404 — Laravel's web
        // behaviour leaking into an API.
        $missingKey = $this->post("/slots/{$slot->id}/hold");
        $missingKey->assertStatus(422);
        $this->assertStringContainsString('application/json', $missingKey->headers->get('Content-Type'));

        $notFound = $this->post('/holds/9999/confirm');
        $notFound->assertNotFound();
        $this->assertStringContainsString('application/json', $notFound->headers->get('Content-Type'));
        $this->assertSame('not_found', $notFound->json('error'));
    }

    #[Test]
    public function it_dispatches_lifecycle_events(): void
    {
        Event::fake([HoldCreated::class, HoldConfirmed::class]);

        $slot = $this->slot();
        $id = $this->hold($slot)->json('hold_id');
        $this->postJson("/holds/{$id}/confirm")->assertOk();

        Event::assertDispatched(HoldCreated::class);
        Event::assertDispatched(HoldConfirmed::class);
    }

    #[Test]
    public function the_status_column_is_cast_to_an_enum(): void
    {
        $slot = $this->slot();
        $id = $this->hold($slot)->json('hold_id');

        $this->assertSame(HoldStatus::Held, Hold::find($id)->status);
    }
}
