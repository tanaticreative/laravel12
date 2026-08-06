<?php

namespace App\Models\Booking;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $name
 * @property int $capacity
 * @property int $remaining
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, \App\Models\Booking\Hold> $holds
 * @property-read int|null $holds_count
 * @method static Builder<static>|Slot newModelQuery()
 * @method static Builder<static>|Slot newQuery()
 * @method static Builder<static>|Slot query()
 * @method static Builder<static>|Slot whereCapacity($value)
 * @method static Builder<static>|Slot whereCreatedAt($value)
 * @method static Builder<static>|Slot whereId($value)
 * @method static Builder<static>|Slot whereName($value)
 * @method static Builder<static>|Slot whereRemaining($value)
 * @method static Builder<static>|Slot whereUpdatedAt($value)
 * @mixin \Eloquent
 */
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
