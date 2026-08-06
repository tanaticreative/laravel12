<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;

class AvailabilityRequest extends FormRequest
{
    /**
     * Both parameters are optional; a caller that sends neither gets the first
     * page at the configured size. Sending nonsense is a 422 rather than a
     * silently clamped value, so a client with an off-by-one is told about it
     * instead of quietly reading the wrong page.
     */
    public function rules(): array
    {
        return [
            'page' => ['integer', 'min:1'],
            'per_page' => ['integer', 'min:1', 'max:'.$this->maxPerPage()],
        ];
    }

    public function page(): int
    {
        return (int) ($this->validated('page') ?? 1);
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? config('booking.availability.per_page'));
    }

    private function maxPerPage(): int
    {
        return (int) config('booking.availability.max_per_page');
    }
}
