<?php

namespace App\Http\Resources;

use App\Support\AccountState;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AccountState
 */
class ClientStateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->client->id,
            'name' => $this->client->name,
            // A decimal string, not a JSON number: 1234.56 has no exact
            // representation as a float, and every consumer parses JSON
            // numbers as floats.
            'cash_balance' => Money::toDecimalString($this->cashBalanceMinor),
            'holdings' => $this->holdings
                ->map(fn (int $quantity, string $instrument): array => [
                    'instrument' => $instrument,
                    'quantity' => $quantity,
                ])
                ->values(),
        ];
    }
}
