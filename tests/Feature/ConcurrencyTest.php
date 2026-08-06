<?php

namespace Tests\Feature;

use App\Enums\HoldStatus;
use App\Exceptions\Booking\ApiException;
use App\Models\Booking\Hold;
use App\Models\Booking\Slot;
use App\Services\SlotService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Genuinely concurrent tests: each worker is a forked process with its own
 * database connection, so the transactions really do race.
 *
 * These deliberately do NOT use RefreshDatabase — its wrapping transaction
 * would hide every child's work from every other child, turning the race into
 * a sequence and making the test prove nothing. Cleanup is manual instead.
 */
class ConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pcntl')) {
            $this->markTestSkipped('pcntl is required to fork real concurrent workers.');
        }

        Hold::query()->delete();
        Slot::query()->delete();
    }

    protected function tearDown(): void
    {
        Hold::query()->delete();
        Slot::query()->delete();

        parent::tearDown();
    }

    /**
     * Run $count copies of $work in parallel, returning each one's exit code.
     *
     * @return list<int>
     */
    private function fork(int $count, callable $work): array
    {
        // Children must not inherit and share the parent's socket.
        DB::disconnect();

        $pids = [];

        for ($i = 0; $i < $count; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('Could not fork a worker.');
            }

            if ($pid === 0) {
                // Child: its own connection, its own transaction.
                DB::reconnect();

                try {
                    $work($i);
                    $code = 0;
                } catch (ApiException) {
                    $code = 1;
                } catch (\Throwable) {
                    $code = 2;
                }

                // exit() rather than return: never let a child fall back into
                // the test runner and report its own results.
                exit($code);
            }

            $pids[] = $pid;
        }

        $codes = [];

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $codes[] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 2;
        }

        DB::reconnect();

        return $codes;
    }

    #[Test]
    public function only_one_of_many_concurrent_holds_wins_the_last_seat(): void
    {
        $slot = Slot::create(['name' => 'race', 'capacity' => 1, 'remaining' => 1]);

        $codes = $this->fork(20, function () use ($slot) {
            app(SlotService::class)->createHold(
                Slot::findOrFail($slot->id),
                'ip:10.0.0.'.getmypid(),
                Str::uuid()->toString(),
                'fingerprint',
            );
        });

        $this->assertSame(1, count(array_filter($codes, fn ($c) => $c === 0)), 'Exactly one hold should be created.');
        $this->assertSame(0, count(array_filter($codes, fn ($c) => $c === 2)), 'No worker should crash.');
        $this->assertSame(1, Hold::count());
    }

    #[Test]
    public function concurrent_confirmations_cannot_oversell(): void
    {
        $slot = Slot::create(['name' => 'race', 'capacity' => 8, 'remaining' => 8]);

        $holdIds = collect(range(1, 8))->map(fn () => Hold::create([
            'slot_id' => $slot->id,
            'actor_key' => 'ip:test',
            'idempotency_key' => Str::uuid()->toString(),
            'request_hash' => 'fingerprint',
            'status' => HoldStatus::Held,
            'expires_at' => now()->addMinutes(5),
        ])->id)->all();

        // Seats disappeared behind the holds' backs; only 3 are really left.
        $slot->update(['remaining' => 3]);

        $codes = $this->fork(8, function (int $i) use ($holdIds) {
            app(SlotService::class)->confirm(Hold::findOrFail($holdIds[$i]));
        });

        $this->assertSame(3, count(array_filter($codes, fn ($c) => $c === 0)), 'Exactly three confirmations should succeed.');
        $this->assertSame(0, count(array_filter($codes, fn ($c) => $c === 2)), 'No worker should crash or deadlock.');
        $this->assertSame(0, $slot->fresh()->remaining);
        $this->assertSame(3, Hold::where('status', HoldStatus::Confirmed)->count());
    }

    #[Test]
    public function concurrent_requests_with_one_key_create_one_hold(): void
    {
        $slot = Slot::create(['name' => 'race', 'capacity' => 50, 'remaining' => 50]);
        $key = Str::uuid()->toString();

        $codes = $this->fork(20, function () use ($slot, $key) {
            app(SlotService::class)->createHold(Slot::findOrFail($slot->id), 'ip:10.0.0.1', $key, 'fingerprint');
        });

        // Every worker succeeds — the losers replay the winner's hold — but
        // only one row may exist.
        $this->assertSame(20, count(array_filter($codes, fn ($c) => $c === 0)));
        $this->assertSame(1, Hold::count());
    }

    #[Test]
    public function mixed_creates_and_confirms_do_not_deadlock(): void
    {
        // Both write paths take the slot lock before the hold lock. If they
        // disagreed on that order, this is where MySQL would report deadlocks.
        $slot = Slot::create(['name' => 'race', 'capacity' => 40, 'remaining' => 40]);

        $seed = collect(range(1, 10))->map(fn () => Hold::create([
            'slot_id' => $slot->id,
            'actor_key' => 'ip:seed',
            'idempotency_key' => Str::uuid()->toString(),
            'request_hash' => 'fingerprint',
            'status' => HoldStatus::Held,
            'expires_at' => now()->addMinutes(5),
        ])->id)->all();

        $codes = $this->fork(20, function (int $i) use ($slot, $seed) {
            if ($i % 2 === 0) {
                app(SlotService::class)->createHold(
                    Slot::findOrFail($slot->id),
                    'ip:10.0.0.'.getmypid(),
                    Str::uuid()->toString(),
                    'fingerprint',
                );
            } else {
                app(SlotService::class)->confirm(Hold::findOrFail($seed[intdiv($i, 2)]));
            }
        });

        $this->assertSame(0, count(array_filter($codes, fn ($c) => $c === 2)), 'A crash here means a deadlock or a lock-order bug.');
        $this->assertGreaterThanOrEqual(0, $slot->fresh()->remaining);
    }
}
