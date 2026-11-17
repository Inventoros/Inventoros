<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ExchangeMath;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Currency conversion is exact decimal math, rounded half up to the target
 * currency's minor units, never float arithmetic.
 */
final class ExchangeMathTest extends TestCase
{
    public function test_converts_exactly_and_rounds_half_up_to_the_cent(): void
    {
        $this->assertSame('364350.00', ExchangeMath::convert('1000.00', '364.35', 'HUF'));
        // 0.1 * 3 would drift as floats; here it is exact.
        $this->assertSame('0.30', ExchangeMath::convert('0.10', '3', 'EUR'));
        // 12.34 * 1.2345 = 15.233730 -> 15.23; 0.125 * 1 rounds up to 0.13.
        $this->assertSame('15.23', ExchangeMath::convert('12.34', '1.2345', 'USD'));
        $this->assertSame('0.13', ExchangeMath::convert('0.125', '1', 'USD'));
        $this->assertSame('-0.13', ExchangeMath::convert('-0.125', '1', 'USD'));
    }

    public function test_zero_decimal_currencies_round_to_whole_units(): void
    {
        // 19.99 EUR at 161.2345 JPY = 3223.077655 -> 3223.
        $this->assertSame('3223.00', ExchangeMath::convert('19.99', '161.2345', 'JPY'));
        $this->assertSame('3224.00', ExchangeMath::convert('19.995', '161.2345', 'KRW'));
        $this->assertSame(0, ExchangeMath::decimals('jpy'));
        $this->assertSame(2, ExchangeMath::decimals('HUF'));
        $this->assertSame(2, ExchangeMath::decimals('XYZ'));
    }

    public function test_rates_are_validated_and_normalized(): void
    {
        $this->assertSame('364.35', ExchangeMath::rate('364.3500000000'));
        $this->assertSame('0.0027446', ExchangeMath::rate(0.0027446));
        $this->assertSame('1', ExchangeMath::rate(1));
        // Beyond ten decimals is rounded, not truncated.
        $this->assertSame('0.0000000002', ExchangeMath::rate('0.00000000015'));
        $this->assertTrue(ExchangeMath::sameRate('364.35', '364.350'));

        foreach (['0', '-1', 'abc', '1e5', '', '12345678901', '0.00000000001'] as $bad) {
            $this->assertFalse(ExchangeMath::isValidRate($bad), "{$bad} should be rejected");
        }

        $this->expectException(InvalidArgumentException::class);
        ExchangeMath::rate('0');
    }
}
