<?php

namespace App\Support;

use App\Enums\TransactionType;
use InvalidArgumentException;

/**
 * A movement a client is asking to record, with its cash amount resolved.
 *
 * A deposit or withdrawal states its amount outright. A trade does not: its
 * amount is quantity x price, derived here so the API, the stored row and the
 * CHECK constraint that re-checks the arithmetic cannot disagree about what a
 * trade cost.
 *
 * All amounts are in minor units, so every calculation is exact integer
 * arithmetic.
 */
final readonly class Movement
{
    private function __construct(
        public TransactionType $type,
        public int $amountMinor,
        public ?string $instrument = null,
        public ?int $quantity = null,
        public ?int $pricePerUnitMinor = null,
    ) {}

    /**
     * Builds a movement from the fields of a validated request, whichever
     * shape the type calls for.
     */
    public static function make(
        TransactionType $type,
        ?int $amountMinor = null,
        ?string $instrument = null,
        ?int $quantity = null,
        ?int $pricePerUnitMinor = null,
    ): self {
        if (! $type->isTrade()) {
            if ($amountMinor === null) {
                throw new InvalidArgumentException("A {$type->value} needs an amount.");
            }

            return new self($type, $amountMinor);
        }

        if ($instrument === null || $quantity === null || $pricePerUnitMinor === null) {
            throw new InvalidArgumentException("A {$type->value} needs an instrument, a quantity and a price.");
        }

        return new self(
            $type,
            self::tradeAmount($quantity, $pricePerUnitMinor),
            $instrument,
            $quantity,
            $pricePerUnitMinor,
        );
    }

    public static function deposit(int $amountMinor): self
    {
        return self::make(TransactionType::Deposit, amountMinor: $amountMinor);
    }

    public static function withdrawal(int $amountMinor): self
    {
        return self::make(TransactionType::Withdrawal, amountMinor: $amountMinor);
    }

    public static function buy(string $instrument, int $quantity, int $pricePerUnitMinor): self
    {
        return self::make(TransactionType::Buy, instrument: $instrument, quantity: $quantity, pricePerUnitMinor: $pricePerUnitMinor);
    }

    public static function sell(string $instrument, int $quantity, int $pricePerUnitMinor): self
    {
        return self::make(TransactionType::Sell, instrument: $instrument, quantity: $quantity, pricePerUnitMinor: $pricePerUnitMinor);
    }

    /**
     * The row this movement becomes.
     *
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return [
            'type' => $this->type,
            'amount_minor' => $this->amountMinor,
            'instrument' => $this->instrument,
            'quantity' => $this->quantity,
            'price_per_unit_minor' => $this->pricePerUnitMinor,
        ];
    }

    /**
     * Refuses to compute a total that would exceed the integer range, where
     * PHP would quietly hand back a float and lose exactness.
     */
    private static function tradeAmount(int $quantity, int $pricePerUnitMinor): int
    {
        if ($pricePerUnitMinor > 0 && intdiv(PHP_INT_MAX, $pricePerUnitMinor) < $quantity) {
            throw new InvalidArgumentException('The total for this trade is too large to represent exactly.');
        }

        return $quantity * $pricePerUnitMinor;
    }
}
