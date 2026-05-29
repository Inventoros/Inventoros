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
}
