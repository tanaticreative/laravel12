<?php

namespace Database\Seeders;

use App\Models\Booking\Slot;
use Illuminate\Database\Seeder;

class SlotSeeder extends Seeder
{
    public function run(): void
    {
        // Mirrors the example payload: one slot with room, one sold out.
        Slot::updateOrCreate(['id' => 1], ['name' => '10:00', 'capacity' => 10, 'remaining' => 6]);
        Slot::updateOrCreate(['id' => 2], ['name' => '11:00', 'capacity' => 5, 'remaining' => 0]);

        // A single-seat slot, for reproducing the oversell conflict.
        Slot::updateOrCreate(['id' => 3], ['name' => '12:00', 'capacity' => 1, 'remaining' => 1]);
    }
}
