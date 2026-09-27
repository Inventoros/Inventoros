<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Auth\Organization;
use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recurring email delivery of a saved report.
 *
 * The report is always generated as its OWNER (the saved report's creator),
 * whose permissions are re-checked at send time; recipients must be members
 * of the same organization.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $saved_report_id
 * @property int $created_by
 * @property string $frequency
 * @property int|null $day_of_week
 * @property int|null $day_of_month
 * @property string $time_of_day
 * @property string $format
 * @property array<int, string> $recipients
 * @property bool $is_active
 * @property CarbonImmutable|null $next_run_at
 * @property CarbonImmutable|null $last_run_at
 * @property string|null $last_status
 */
class ReportSchedule extends Model
{
    use BelongsToOrganization;

    public const FREQUENCIES = ['daily', 'weekly', 'monthly'];

    public const MAX_RECIPIENTS = 50;

    protected $fillable = [
        'organization_id',
        'saved_report_id',
        'created_by',
        'frequency',
        'day_of_week',
        'day_of_month',
        'time_of_day',
        'format',
        'recipients',
        'is_active',
        'next_run_at',
        'last_run_at',
        'last_status',
    ];

    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'is_active' => 'boolean',
            'day_of_week' => 'integer',
            'day_of_month' => 'integer',
            'next_run_at' => 'immutable_datetime',
            'last_run_at' => 'immutable_datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function savedReport(): BelongsTo
    {
        return $this->belongsTo(SavedReport::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The organization's time zone (UTC when unset or unknown). */
    public function timezone(): string
    {
        $timezone = (string) Organization::query()->withoutGlobalScopes()->whereKey($this->organization_id)->value('timezone');

        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
    }

    /** The next run strictly after $after, in UTC. */
    public function computeNextRunAt(CarbonInterface $after): CarbonImmutable
    {
        return self::nextRunAfter($this->frequency, $this->day_of_week, $this->day_of_month, $this->time_of_day, $this->timezone(), $after);
    }

    /**
     * The first run time strictly after $after for a schedule, evaluated in
     * $timezone (the organization's) and returned in UTC.
     *
     * daily   : every day at time_of_day
     * weekly  : on day_of_week (0 = Sunday) at time_of_day
     * monthly : on day_of_month at time_of_day, clamped to the last day of
     *           shorter months (31 runs on Feb 28/29, Apr 30, ...)
     */
    public static function nextRunAfter(
        string $frequency,
        ?int $dayOfWeek,
        ?int $dayOfMonth,
        string $timeOfDay,
        string $timezone,
        CarbonInterface $after,
    ): CarbonImmutable {
        $timezone = in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
        [$hour, $minute] = array_map('intval', explode(':', $timeOfDay) + [0, 0]);

        $local = CarbonImmutable::instance($after)->setTimezone($timezone);
        $at = fn (CarbonImmutable $day) => $day->setTime($hour, $minute, 0);

        switch ($frequency) {
            case 'weekly':
                $target = $dayOfWeek ?? 1;
                $candidate = $at($local->addDays(($target - $local->dayOfWeek + 7) % 7));
                if ($candidate->lessThanOrEqualTo($local)) {
                    $candidate = $candidate->addWeek();
                }
                break;

            case 'monthly':
                $day = max(1, $dayOfMonth ?? 1);
                $inMonth = fn (CarbonImmutable $month) => $at($month->setDate($month->year, $month->month, min($day, $month->daysInMonth)));
                $candidate = $inMonth($local->startOfMonth());
                if ($candidate->lessThanOrEqualTo($local)) {
                    $candidate = $inMonth($local->startOfMonth()->addMonthNoOverflow());
                }
                break;

            default: // daily
                $candidate = $at($local);
                if ($candidate->lessThanOrEqualTo($local)) {
                    $candidate = $at($local->addDay());
                }
        }

        return $candidate->utc();
    }
}
