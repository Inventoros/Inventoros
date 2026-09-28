<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

/**
 * P2-1 — exact fixed-point money arithmetic.
 */
class MoneyTest extends TestCase
{
    public function test_add_is_exact_where_floats_drift(): void
    {
        // 0.1 + 0.2 is the canonical binary-float drift (0.30000000000000004).
        $this->assertSame('0.30', Money::add('0.10', '0.20'));
    }

    public function test_add_normalizes_to_two_decimals(): void
    {
        $this->assertSame('30.00', Money::add('10', '20'));
        $this->assertSame('24.00', Money::add('20.00', '4.00', 0));
    }

    public function test_add_handles_null_as_zero(): void
    {
        $this->assertSame('5.00', Money::add('5.00', null));
    }

    public function test_multiply_quantity_by_unit_price_is_exact(): void
    {
        $this->assertSame('30.00', Money::multiply('10.00', 3));
        $this->assertSame('0.30', Money::multiply('0.10', 3));
    }

    public function test_multiply_rounds_half_up_to_the_cent_instead_of_truncating(): void
    {
        // 0.125 * 3 = 0.375: bcmath at scale 2 truncated this to 0.37.
        $this->assertSame('0.38', Money::multiply('0.125', 3));
        $this->assertSame('10.00', Money::multiply('19.99', '0.5'));
        $this->assertSame('0.33', Money::multiply('0.333', 1));
        $this->assertSame('-0.38', Money::multiply('-0.125', 3));
        $this->assertSame('0.00', Money::multiply('0.004', 1));
    }

    public function test_of_rounds_half_up_to_the_cent_instead_of_truncating(): void
    {
        $this->assertSame('0.13', Money::of('0.125'));
        $this->assertSame('0.12', Money::of('0.1249'));
        $this->assertSame('-0.13', Money::of('-0.125'));
        $this->assertSame('1.01', Money::of(1.005));
        $this->assertSame('19.99', Money::of(19.99));
    }

    public function test_add_and_subtract_round_rather_than_truncate_each_input(): void
    {
        // Each 0.005 used to be truncated to 0.00 before summing.
        $this->assertSame('0.01', Money::add('0.005', '0.005'));
        $this->assertSame('0.13', Money::add('0.125'));
        $this->assertSame('9.99', Money::subtract('10', '0.005', '0.005'));
    }

    public function test_floats_in_scientific_notation_are_accepted(): void
    {
        // (string) 1.0E-5 is "1.0E-5", which bcmath rejects outright.
        $this->assertSame('0.00', Money::of(0.00001));
        $this->assertSame('0.00', Money::multiply(0.00001, 2));
        $this->assertSame('100000000000000000000.00', Money::of(1.0E20));
    }

    public function test_summing_many_cents_does_not_drift(): void
    {
        // 10 * 0.01 must be exactly 0.10, not 0.099999...
        $total = '0';
        for ($i = 0; $i < 10; $i++) {
            $total = Money::add($total, '0.01');
        }
        $this->assertSame('0.10', $total);
    }

    public function test_of_normalizes_mixed_inputs(): void
    {
        $this->assertSame('10.00', Money::of(10));
        $this->assertSame('10.50', Money::of('10.5'));
        $this->assertSame('0.00', Money::of(null));
    }

    public function test_subtract_is_exact_and_may_go_negative(): void
    {
        $this->assertSame('0.10', Money::subtract('0.30', '0.20'));
        $this->assertSame('-5.00', Money::subtract('10', '15'));
        $this->assertSame('7.50', Money::subtract('10.00', '1.25', '1.25'));
    }

    public function test_percent_of_rounds_half_up_to_the_cent(): void
    {
        // 15% of 10.05 = 1.5075 -> 1.51 (bcmath alone would truncate to 1.50).
        $this->assertSame('1.51', Money::percentOf('10.05', '15'));
        $this->assertSame('3.33', Money::percentOf('33.33', '10'));
        $this->assertSame('100.00', Money::percentOf('100.00', '100'));
        $this->assertSame('0.00', Money::percentOf('100.00', '0'));
        // 12.5% of 0.20 = 0.025 -> 0.03
        $this->assertSame('0.03', Money::percentOf('0.20', '12.5'));
    }

    public function test_compare(): void
    {
        $this->assertSame(0, Money::compare('10', '10.00'));
        $this->assertSame(1, Money::compare('10.01', '10'));
        $this->assertSame(-1, Money::compare('-0.01', 0));
        $this->assertTrue(Money::isNegative('-0.01'));
        $this->assertFalse(Money::isNegative('0.00'));
        $this->assertTrue(Money::isZero('0'));
    }

    public function test_max_and_min(): void
    {
        $this->assertSame('5.00', Money::max('5', '-3'));
        $this->assertSame('-3.00', Money::min('5', '-3'));
    }
}
