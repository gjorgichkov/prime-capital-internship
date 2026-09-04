<?php

namespace App\Exceptions;

/**
 * The account rule that a client cannot sell units they do not hold.
 */
final class InsufficientHoldingsException extends AccountRuleViolation
{
    public static function forSale(string $instrument, int $requestedUnits, int $heldUnits): self
    {
        return new self(sprintf(
            'Cannot sell %d unit(s) of %s: the client holds %d.',
            $requestedUnits,
            $instrument,
            $heldUnits,
        ));
    }

    public function code(): string
    {
        return 'insufficient_holdings';
    }

    public function field(): string
    {
        return 'quantity';
    }
}
