<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    /**
     * Each case is the accepted input, the stored minor units, and the
     * canonical decimal string that comes back out.
     *
     * @return array<string, array{string, int, string}>
     */
    public static function amounts(): array
    {
        return [
            'whole number without decimals' => ['1000', 100000, '1000.00'],
            'whole number with decimals' => ['1000.00', 100000, '1000.00'],
            'a single decimal place' => ['120.5', 12050, '120.50'],
            'two decimal places' => ['120.50', 12050, '120.50'],
            'cents only' => ['0.05', 5, '0.05'],
            'zero' => ['0.00', 0, '0.00'],
            'an amount the naive float cast corrupts' => ['0.29', 29, '0.29'],
            'another the float cast corrupts' => ['1.13', 113, '1.13'],
            'beyond the exact range of a float' => ['92233720368547.75', 9223372036854775, '92233720368547.75'],
            'negative' => ['-120.50', -12050, '-120.50'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedAmounts(): array
    {
        return [
            'three decimal places' => ['10.005'],
            'not a number' => ['abc'],
            'empty' => [''],
            'only a decimal point' => ['.'],
            'no leading digit' => ['.50'],
            'thousands separator' => ['1,000.00'],
            'leading whitespace' => [' 10.00'],
            'scientific notation' => ['1e3'],
            'a trailing decimal point' => ['10.'],
        ];
    }

    #[DataProvider('amounts')]
    public function test_it_converts_decimal_strings_to_minor_units(string $input, int $minor, string $canonical): void
    {
        $this->assertSame($minor, Money::fromDecimalString($input));

        // The canonical form has to land on the same integer as the shorthand,
        // so that '120.5' and '120.50' are not two different amounts.
        $this->assertSame($minor, Money::fromDecimalString($canonical));
    }

    #[DataProvider('amounts')]
    public function test_it_converts_minor_units_back_to_decimal_strings(string $input, int $minor, string $canonical): void
    {
        $this->assertSame($canonical, Money::toDecimalString($minor));
    }

    #[DataProvider('malformedAmounts')]
    public function test_it_rejects_amounts_it_cannot_represent_exactly(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimalString($value);
    }

    public function test_every_cent_survives_a_round_trip(): void
    {
        for ($minor = 0; $minor <= 20000; $minor++) {
            $this->assertSame($minor, Money::fromDecimalString(Money::toDecimalString($minor)));
        }
    }
}
