<?php

use App\Enums\HoldStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('slot_id')->constrained()->cascadeOnDelete();
            // if authenticated user, then user_id else ip
            $table->string('actor_key');
            $table->uuid('idempotency_key');
            //extra check for duplicate requests
            // if the same key but a different payload is rejected
            $table->char('request_hash',64);
            $table->enum('status', [HoldStatus::Held, HoldStatus::Confirmed, HoldStatus::Cancelled])->default(HoldStatus::Held);
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            // The guarantee behind idempotency: concurrent duplicates lose here,
            // in the database, not in an application-level "does it exist" check.
            // Scoped per actor, so keys are namespaced rather than global.
            $table->unique(['actor_key', 'idempotency_key']);

            // Counting live holds for a slot is on the hot path of both
            // availability and hold creation.
            $table->index(['slot_id', 'status', 'expires_at']);

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('holds');
    }
};
