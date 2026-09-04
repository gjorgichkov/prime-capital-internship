<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Converts between the decimal strings used at the API boundary and the
 * integer minor units used for storage and arithmetic.
 *
 * No float is involved at any point, deliberately. Most two-decimal amounts
 * have no exact binary representation, so the obvious `(int) ($value * 100)`
 * loses a cent surprisingly often: 0.29 becomes 28, and so do 9,174 of the
 * first 200,000 cent amounts.
 */
final class Money
{
    /**
     * Decimal places in the major unit.
     */
    public const SCALE = 2;

    /**
     * Minor units per major unit, as a string for bcmath.
     */
    private const FACTOR = '100';

    /**
     * An optional sign, digits, and at most SCALE decimal places.
     */
    private const PATTERN = '/^-?\d+(\.\d{1,2})?$/';

    /**
     * @throws InvalidArgumentException if $value is not a decimal amount with
     *                                  at most two fractional digits
     */
    public static function fromDecimalString(string $value): int
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(sprintf(
                '"%s" is not a monetary amount with at most %d decimal places.',
                $value,
                self::SCALE,
            ));
        }

        // A scale of 0 truncates, but the pattern above has already rejected
        // any third decimal that truncation could silently discard.
        $minor = bcmul($value, self::FACTOR, 0);

        // Casting an out-of-range numeric string saturates at PHP_INT_MAX
        // rather than failing, which would turn an absurd amount into a
        // merely enormous one. Refuse instead of quietly changing it.
        if (bccomp($minor, (string) PHP_INT_MAX) > 0 || bccomp($minor, (string) PHP_INT_MIN) < 0) {
            throw new InvalidArgumentException(
                sprintf('"%s" is outside the range that can be represented exactly.', $value),
            );
        }

        return (int) $minor;
    }

    public static function toDecimalString(int $minor): string
    {
        return bcdiv((string) $minor, self::FACTOR, self::SCALE);
    }
}
