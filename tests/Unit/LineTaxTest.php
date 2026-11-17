<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\LineTax;
use App\Support\Money;
use PHPUnit\Framework\TestCase;

/**
 * Per-line tax from rates (#416): the shared calculator orders use and
 * purchase orders can reuse.
 */
final class LineTaxTest extends TestCase
{
    public function test_rate_normalizes_to_three_decimals_and_keeps_null_apart_from_zero(): void
    {
        $this->assertNull(LineTax::rate(null));
        $this->assertNull(LineTax::rate(''));
        $this->assertNull(LineTax::rate('abc'));
        $this->assertSame('0.000', LineTax::rate(0));
        $this->assertSame('27.000', LineTax::rate('27'));
        $this->assertSame('5.500', LineTax::rate(5.5));
        $this->assertSame('9.375', LineTax::rate('9.375'));
    }

    public function test_allocate_keeps_every_cent_in_line_order(): void
    {
        $this->assertSame(['a' => '3.33', 'b' => '3.34', 'c' => '3.33'], Money::allocate('10.00', ['a' => 1, 'b' => 1, 'c' => 1]));
        $this->assertSame(['a' => '0.00', 'b' => '0.00'], Money::allocate('5.00', ['a' => 0, 'b' => 0]));
        $this->assertSame('10.00', Money::add(...array_values(Money::allocate('10.00', [7, 11, 13, 17]))));
    }

    public function test_each_rate_is_applied_to_its_own_lines(): void
    {
        $taxed = LineTax::compute([
            'book' => ['net' => '100.00', 'tax_rate' => '5'],
            'pen' => ['net' => '10.00', 'tax_rate' => '27'],
        ]);

        $this->assertSame('5.00', $taxed['book']['tax']);
        $this->assertSame('2.70', $taxed['pen']['tax']);
        $this->assertSame('100.00', $taxed['book']['taxable']);
        $this->assertSame('5.000', $taxed['book']['tax_rate']);
    }

    public function test_lines_without_a_rate_keep_their_amount(): void
    {
        $taxed = LineTax::compute([
            ['net' => '50.00', 'tax' => '3.21'],
            ['net' => '50.00', 'tax_rate' => null, 'tax' => null],
        ]);

        $this->assertSame('3.21', $taxed[0]['tax']);
        $this->assertSame('0.00', $taxed[1]['tax']);
        $this->assertNull($taxed[0]['tax_rate']);
    }

    public function test_a_zero_rate_is_a_rate(): void
    {
        $taxed = LineTax::compute([['net' => '50.00', 'tax_rate' => '0', 'tax' => '9.99']]);

        $this->assertSame('0.00', $taxed[0]['tax']);
        $this->assertSame('0.000', $taxed[0]['tax_rate']);
    }

    public function test_a_document_discount_is_shared_across_all_lines_before_tax(): void
    {
        // 10% off 200.00 of merchandise: each line loses 10% of its net,
        // including the line without a rate.
        $taxed = LineTax::compute([
            ['net' => '100.00', 'tax_rate' => '27'],
            ['net' => '50.00', 'tax_rate' => '5'],
            ['net' => '50.00', 'tax' => '1.00'],
        ], '20.00');

        $this->assertSame('90.00', $taxed[0]['taxable']);
        $this->assertSame('24.30', $taxed[0]['tax']);
        $this->assertSame('45.00', $taxed[1]['taxable']);
        $this->assertSame('2.25', $taxed[1]['tax']);
        $this->assertSame('45.00', $taxed[2]['taxable']);
        $this->assertSame('1.00', $taxed[2]['tax']);
    }

    public function test_tax_per_rate_is_rounded_once_and_split_back_across_its_lines(): void
    {
        // Three lines of 0.10 at 27%: per-line rounding would charge
        // 3 x 0.03 = 0.09; 27% of 0.30 is 0.081, so the rate charges 0.08.
        $taxed = LineTax::compute([
            ['net' => '0.10', 'tax_rate' => '27'],
            ['net' => '0.10', 'tax_rate' => '27'],
            ['net' => '0.10', 'tax_rate' => '27'],
        ]);

        $this->assertSame('0.08', Money::add(...array_column($taxed, 'tax')));
        $this->assertSame(['0.03', '0.02', '0.03'], array_column($taxed, 'tax'));
    }

    public function test_rounding_is_half_away_from_zero(): void
    {
        // 27% of 0.50 = 0.135 -> 0.14; 5% of 0.10 = 0.005 -> 0.01.
        $this->assertSame('0.14', LineTax::compute([['net' => '0.50', 'tax_rate' => '27']])[0]['tax']);
        $this->assertSame('0.01', LineTax::compute([['net' => '0.10', 'tax_rate' => '5']])[0]['tax']);
    }

    public function test_a_discount_cannot_take_the_taxable_amount_below_zero(): void
    {
        $taxed = LineTax::compute([['net' => '10.00', 'tax_rate' => '27']], '50.00');

        $this->assertSame('0.00', $taxed[0]['taxable']);
        $this->assertSame('0.00', $taxed[0]['tax']);
    }

    public function test_summarize_groups_by_rate_highest_first_and_skips_unrated_lines(): void
    {
        $summary = LineTax::summarize(LineTax::compute([
            ['net' => '10.00', 'tax_rate' => '5'],
            ['net' => '20.00', 'tax_rate' => '27'],
            ['net' => '30.00', 'tax_rate' => '5'],
            ['net' => '40.00', 'tax' => '2.00'],
        ]));

        $this->assertSame([
            ['rate' => '27.000', 'taxable' => '20.00', 'tax' => '5.40'],
            ['rate' => '5.000', 'taxable' => '40.00', 'tax' => '2.00'],
        ], $summary);
    }
}
