<?php

declare(strict_types=1);

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * An inclusive whole-day date range for a report, plus the equal-length
 * period immediately before it (for period-over-period comparison).
 */
final class ReportPeriod
{
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
    ) {}

    /**
     * Read date_from / date_to (Y-m-d) from the request. Missing or malformed
     * values fall back to the last $defaultDays days ending today, and a
     * reversed range is swapped rather than rejected.
     */
    public static function fromRequest(Request $request, int $defaultDays = 30): self
    {
        $to = self::parse($request->query('date_to')) ?? CarbonImmutable::now();
        $from = self::parse($request->query('date_from')) ?? $to->subDays($defaultDays);

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return new self($from->startOfDay(), $to->endOfDay());
    }

    public static function fromDates(string $from, string $to): self
    {
        return new self(
            CarbonImmutable::parse($from)->startOfDay(),
            CarbonImmutable::parse($to)->endOfDay(),
        );
    }

    /** Number of calendar days covered, inclusive of both ends. */
    public function days(): int
    {
        return (int) $this->from->startOfDay()->diffInDays($this->to->startOfDay()) + 1;
    }

    /** The equal-length period ending the day before this one starts. */
    public function previous(): self
    {
        $to = $this->from->subDay()->endOfDay();

        return new self($to->subDays($this->days() - 1)->startOfDay(), $to);
    }

    /** @return array{date_from: string, date_to: string} */
    public function toFilters(): array
    {
        return [
            'date_from' => $this->from->format('Y-m-d'),
            'date_to' => $this->to->format('Y-m-d'),
        ];
    }

    public function label(): string
    {
        return $this->from->format('Y-m-d').' to '.$this->to->format('Y-m-d');
    }

    private static function parse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }
}
