<?php

namespace App\Http\Resources;

use App\Models\Transaction;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Transaction
 */
class TransactionResource extends JsonResource
{
    /**
     * The instrument fields stay present and null on cash movements, so every
     * entry in the audit trail has the same shape.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'amount' => Money::toDecimalString($this->amount_minor),
            'instrument' => $this->instrument,
            'quantity' => $this->quantity,
            'price_per_unit' => $this->price_per_unit_minor === null
                ? null
                : Money::toDecimalString($this->price_per_unit_minor),
            'created_at' => $this->created_at,
        ];
    }
}
