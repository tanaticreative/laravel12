<?php

namespace App\Http\Requests\Booking;

use App\Models\Booking\Hold;
use App\Support\Booking\ActorKey;
use Illuminate\Foundation\Http\FormRequest;

class CreateHoldRequest extends FormRequest
{
    /**
     * The header is part of the contract, so a missing or malformed key is a
     * 422 rather than a silently non-idempotent write.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => $this->header('Idempotency-Key'),
        ]);
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'The Idempotency-Key header is required.',
            'idempotency_key.uuid' => 'The Idempotency-Key header must be a UUID.',
        ];
    }

    public function idempotencyKey(): string
    {
        return $this->validated('idempotency_key');
    }


    /**
     * Who this key belongs to.
     *
     * An Idempotency-Key is not a credential — anyone can invent one — so it
     * is namespaced by the caller's identity. Authentication decides *who*;
     * the key only decides *whether this is a retry*.
     */
    public function actorKey(): string
    {
        return ActorKey::for($this);
    }

    /**
     * Fingerprint of what was actually asked for, so replaying a key with a
     * different payload can be told apart from a genuine retry.
     */
    public function payloadFingerprint(int $slotId): string
    {
        return Hold::fingerprint([
                'slot_id' => $slotId,
            ] + $this->except('idempotency_key'));
    }
}
