<?php

namespace App\Models\Booking;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Slot extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'capacity', 'remaining'];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'remaining' => 'integer',
        ];
    }

    public function holds(): HasMany
    {
        return $this->hasMany(Hold::class);
    }
}
