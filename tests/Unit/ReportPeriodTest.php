<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Reports\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class ReportPeriodTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_days_are_inclusive_and_previous_period_has_equal_length(): void
    {
        $period = ReportPeriod::fromDates('2026-06-11', '2026-06-20');

        $this->assertSame(10, $period->days());
        $this->assertSame(['date_from' => '2026-06-01', 'date_to' => '2026-06-10'], $period->previous()->toFilters());
        $this->assertSame('2026-06-10 23:59:59', $period->previous()->to->format('Y-m-d H:i:s'));
    }

    public function test_request_values_are_parsed_and_malformed_ones_fall_back(): void
    {
        CarbonImmutable::setTestNow('2026-06-30 12:00:00');

        $parsed = ReportPeriod::fromRequest(Request::create('/', 'GET', ['date_from' => '2026-06-01', 'date_to' => '2026-06-05']));
        $this->assertSame(['date_from' => '2026-06-01', 'date_to' => '2026-06-05'], $parsed->toFilters());

        $fallback = ReportPeriod::fromRequest(Request::create('/', 'GET', ['date_from' => "2026-02-31' OR 1=1", 'date_to' => 'nope']));
        $this->assertSame(['date_from' => '2026-05-31', 'date_to' => '2026-06-30'], $fallback->toFilters());
    }

    public function test_a_reversed_range_is_swapped(): void
    {
        $period = ReportPeriod::fromRequest(Request::create('/', 'GET', ['date_from' => '2026-06-10', 'date_to' => '2026-06-01']));

        $this->assertSame(['date_from' => '2026-06-01', 'date_to' => '2026-06-10'], $period->toFilters());
    }
}
