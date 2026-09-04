<?php

namespace App\Http\Requests;

use App\Enums\TransactionType;
use App\Rules\MonetaryAmount;
use App\Support\Money;
use App\Support\Movement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreTransactionRequest extends FormRequest
{
    /**
     * Ceilings picked so that a trade total can never leave the integer range:
     * 1,000,000,000 units at 92,000,000.00 each is 9.2e18 minor units, just
     * inside PHP_INT_MAX of roughly 9.223e18. Both sit far above any real
     * trade, and stating them here keeps the overflow guard inside Movement
     * unreachable from the API.
     */
    private const MAX_QUANTITY = 1_000_000_000;

    private const MAX_PRICE_PER_UNIT = 92_000_000;

    private const MAX_AMOUNT = 92_000_000_000_000;

    /**
     * The prohibited_if rules matter as much as the required_if ones: they stop
     * a deposit from smuggling in a quantity, so the API rejects an incoherent
     * movement itself rather than leaving the database to complain about it.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tradeOnly = ['required_if:type,buy,sell', 'prohibited_if:type,deposit,withdrawal'];

        return [
            'type' => ['required', Rule::enum(TransactionType::class)],

            'amount' => [
                'required_if:type,deposit,withdrawal',
                'prohibited_if:type,buy,sell',
                // decimal earns its place next to MonetaryAmount: it is what
                // makes gt and max compare numerically rather than by length.
                'decimal:0,2',
                'gt:0',
                'max:'.self::MAX_AMOUNT,
                new MonetaryAmount,
            ],

            'instrument' => [
                ...$tradeOnly,
                'string',
                'max:20',
                // Tickers in practice: letters, digits, dots and hyphens.
                'regex:/^[A-Z0-9.\-]+$/',
            ],

            'quantity' => [
                ...$tradeOnly,
                'integer',
                'min:1',
                'max:'.self::MAX_QUANTITY,
            ],

            'price_per_unit' => [
                ...$tradeOnly,
                'decimal:0,2',
                'gt:0',
                'max:'.self::MAX_PRICE_PER_UNIT,
                new MonetaryAmount,
            ],
        ];
    }

    /**
     * The validated movement, with its amount already in minor units.
     */
    public function toMovement(): Movement
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return Movement::make(
            TransactionType::from((string) $validated['type']),
            isset($validated['amount']) ? Money::fromDecimalString((string) $validated['amount']) : null,
            isset($validated['instrument']) ? (string) $validated['instrument'] : null,
            isset($validated['quantity']) ? (int) $validated['quantity'] : null,
            isset($validated['price_per_unit']) ? Money::fromDecimalString((string) $validated['price_per_unit']) : null,
        );
    }

    /**
     * Tickers are matched case sensitively in the database, so they are
     * normalised here instead. It means 'aapl' and 'AAPL' are one holding
     * rather than two.
     */
    protected function prepareForValidation(): void
    {
        $instrument = $this->input('instrument');

        if (is_string($instrument)) {
            $this->merge(['instrument' => Str::upper($instrument)]);
        }
    }
}
