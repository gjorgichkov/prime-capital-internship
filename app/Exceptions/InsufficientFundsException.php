<?php

namespace App\Exceptions;

use App\Support\Money;

/**
 * The account rule that cash may never go negative.
 */
final class InsufficientFundsException extends AccountRuleViolation
{
    private function __construct(string $message, private readonly string $field)
    {
        parent::__construct($message);
    }

    public static function forWithdrawal(int $requestedMinor, int $balanceMinor): self
    {
        return new self(
            sprintf(
                'Cannot withdraw %s: the available cash balance is %s.',
                Money::toDecimalString($requestedMinor),
                Money::toDecimalString($balanceMinor),
            ),
            'amount',
        );
    }

    public static function forPurchase(int $requestedMinor, int $balanceMinor): self
    {
        return new self(
            sprintf(
                'Cannot spend %s on this purchase: the available cash balance is %s.',
                Money::toDecimalString($requestedMinor),
                Money::toDecimalString($balanceMinor),
            ),
            // A purchase has no amount field of its own; the quantity is what
            // the caller would reduce to fit the balance.
            'quantity',
        );
    }

    public function code(): string
    {
        return 'insufficient_funds';
    }

    public function field(): string
    {
        return $this->field;
    }
}
