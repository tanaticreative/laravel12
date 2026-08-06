<?php

namespace Tests\Feature;

use App\Models\Booking\Slot;
use App\Services\SlotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AvailabilityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_returns_the_documented_shape(): void
    {
        Slot::create(['name' => '10:00', 'capacity' => 10, 'remaining' => 6]);
        Slot::create(['name' => '11:00', 'capacity' => 5, 'remaining' => 0]);

        $this->getJson('/slots/availability')
            ->assertOk()
            ->assertExactJson([
                ['slot_id' => 1, 'capacity' => 10, 'remaining' => 6],
                ['slot_id' => 2, 'capacity' => 5, 'remaining' => 0],
            ]);
    }

    #[Test]
    public function it_subtracts_live_holds_from_the_advertised_remainder(): void
    {
        $slot = Slot::create(['capacity' => 5, 'remaining' => 5]);

        $this->withHeader('Idempotency-Key', Str::uuid()->toString())
            ->postJson("/slots/{$slot->id}/hold")
            ->assertCreated();

        // A seat somebody is holding must not be advertised to anyone else.
        $this->getJson('/slots/availability')
            ->assertJsonPath('0.remaining', 4);
    }

    #[Test]
    public function it_serves_repeat_reads_from_cache(): void
    {
        Slot::create(['capacity' => 5, 'remaining' => 5]);

        $this->getJson('/slots/availability')->assertOk();

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->getJson('/slots/availability')->assertOk();

        $this->assertSame(0, $queries, 'A cached read should not touch the database.');
    }

    #[Test]
    public function creating_a_hold_invalidates_the_cache(): void
    {
        $slot = Slot::create(['capacity' => 5, 'remaining' => 5]);

        $this->getJson('/slots/availability')->assertJsonPath('0.remaining', 5);

        $this->withHeader('Idempotency-Key', Str::uuid()->toString())
            ->postJson("/slots/{$slot->id}/hold")->assertCreated();

        // Without invalidation this would still read 5 for up to 15 seconds.
        $this->getJson('/slots/availability')->assertJsonPath('0.remaining', 4);
    }

    #[Test]
    public function confirming_invalidates_the_cache(): void
    {
        $slot = Slot::create(['capacity' => 5, 'remaining' => 5]);
        $id = $this->withHeader('Idempotency-Key', Str::uuid()->toString())
            ->postJson("/slots/{$slot->id}/hold")->json('hold_id');

        $this->getJson('/slots/availability')->assertJsonPath('0.remaining', 4);

        $this->postJson("/holds/{$id}/confirm")->assertOk();

        $this->getJson('/slots/availability')->assertJsonPath('0.remaining', 4);
        $this->assertSame(4, $slot->fresh()->remaining);
    }

    #[Test]
    public function cancelling_invalidates_the_cache(): void
    {
        $slot = Slot::create(['capacity' => 5, 'remaining' => 5]);
        $id = $this->withHeader('Idempotency-Key', Str::uuid()->toString())
            ->postJson("/slots/{$slot->id}/hold")->json('hold_id');

        $this->getJson('/slots/availability')->assertJsonPath('0.remaining', 4);

        $this->deleteJson("/holds/{$id}")->assertOk();

        $this->getJson('/slots/availability')->assertJsonPath('0.remaining', 5);
    }

    #[Test]
    public function invalidation_also_drops_the_stale_copy(): void
    {
        $slot = Slot::create(['capacity' => 5, 'remaining' => 5]);
        $this->getJson('/slots/availability')->assertOk();

        app(SlotService::class)->availability();
        $this->assertNotNull(Cache::get('slots:availability:stale'));

        app(\App\Services\AvailabilityCacheService::class)->invalidate();

        // A stale copy that survived a write would be served as truth by any
        // reader that loses the recompute race.
        $this->assertNull(Cache::get('slots:availability'));
        $this->assertNull(Cache::get('slots:availability:stale'));
    }
}
