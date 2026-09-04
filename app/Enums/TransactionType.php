<?php

namespace App\Enums;

enum TransactionType: string
{
    case Deposit = 'deposit';
    case Withdrawal = 'withdrawal';
    case Buy = 'buy';
    case Sell = 'sell';

    /**
     * Whether the movement concerns an instrument rather than cash alone.
     */
    public function isTrade(): bool
    {
        return $this === self::Buy || $this === self::Sell;
    }

    /**
     * +1 when the movement puts cash into the account, -1 when it takes cash
     * out. Selling adds cash and buying spends it, which is why this cannot
     * simply follow isTrade().
     */
    public function cashDirection(): int
    {
        return match ($this) {
            self::Deposit, self::Sell => 1,
            self::Withdrawal, self::Buy => -1,
        };
    }

    /**
     * +1 when the movement adds units of an instrument, -1 when it removes
     * them, and 0 for movements that touch no instrument at all.
     */
    public function unitDirection(): int
    {
        return match ($this) {
            self::Buy => 1,
            self::Sell => -1,
            self::Deposit, self::Withdrawal => 0,
        };
    }
}
