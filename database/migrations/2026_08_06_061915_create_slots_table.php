<?php

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
        Schema::create('slots', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->unsignedInteger('capacity')->default(0);

            // Seats left after confirmations.
            $table->unsignedInteger('available')->default(0);
            $table->timestamps();
        });

        //if not preorder
        //final defense

        DB::statement('ALTER TABLE slots ADD CONSTRAINT slots_remaining_not_negative CHECK (available >= 0)');
        DB::statement('ALTER TABLE slots ADD CONSTRAINT slots_remaining_within_capacity CHECK (available <= capacity)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('slots');
    }
};
