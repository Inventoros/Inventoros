<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\Permission;
use App\Mail\ScheduledReportEmail;
use App\Models\ReportSchedule;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the saved reports whose schedule is due.
 *
 * Each due schedule is first CLAIMED by moving next_run_at forward with a
 * compare-and-set update, so overlapping scheduler runs (or several app
 * servers) never send the same slot twice, and a schedule that is skipped or
 * fails waits for its next slot instead of retrying every tick.
 *
 * The report is generated as the saved report's OWNER. At send time the owner
 * must still belong to the schedule's organization and still hold
 * view_reports; the data source's own view permission is re-checked by
 * ReportDataService::executeReport. If any check fails the delivery is skipped
 * and logged. Recipients are re-filtered to current members of the
 * organization, so someone who has left stops receiving it.
 */
class ScheduledReportRunner
{
    public function __construct(private readonly SavedReportRenderer $renderer) {}

    /**
     * @return array{due: int, sent: int, skipped: int, failed: int}
     */
    public function runDue(CarbonInterface $now): array
    {
        $stats = ['due' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0];

        ReportSchedule::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('is_active', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $now)
            ->chunkById(100, function ($schedules) use ($now, &$stats) {
                foreach ($schedules as $schedule) {
                    if (! $this->claim($schedule, $now)) {
                        continue;
                    }

                    $stats['due']++;
                    $status = $this->send($schedule);
                    $stats[$status]++;

                    ReportSchedule::query()
                        ->withoutGlobalScope(OrganizationScope::class)
                        ->whereKey($schedule->id)
                        ->update(['last_status' => $status]);
                }
            });

        return $stats;
    }

    /**
     * Move next_run_at to the following slot, but only if no other run has
     * moved it since this one read it.
     */
    private function claim(ReportSchedule $schedule, CarbonInterface $now): bool
    {
        return ReportSchedule::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->whereKey($schedule->id)
            ->where('next_run_at', $schedule->getRawOriginal('next_run_at'))
            ->update([
                'next_run_at' => $schedule->computeNextRunAt($now),
                'last_run_at' => $now,
            ]) === 1;
    }

    /**
     * @return 'sent'|'skipped'|'failed'
     */
    public function send(ReportSchedule $schedule): string
    {
        $report = $schedule->savedReport()->first();
        $owner = $report !== null ? User::query()->find($report->created_by) : null;

        if ($report === null || $owner === null
            || (int) $report->organization_id !== (int) $schedule->organization_id
            || (int) $owner->organization_id !== (int) $schedule->organization_id) {
            return $this->skip($schedule, 'owner_unavailable');
        }

        if (! $owner->hasPermission(Permission::VIEW_REPORTS)) {
            return $this->skip($schedule, 'permission_revoked', ['permission' => Permission::VIEW_REPORTS->value]);
        }

        $recipients = $this->currentRecipients($schedule);
        if ($recipients === []) {
            return $this->skip($schedule, 'no_recipients');
        }

        try {
            $rendered = $this->renderer->render($report, $owner, $schedule->format);
        } catch (AuthorizationException $e) {
            return $this->skip($schedule, 'permission_revoked', ['data_source' => $report->data_source]);
        } catch (\Throwable $e) {
            Log::error('Scheduled report failed to render', [
                'schedule_id' => $schedule->id,
                'saved_report_id' => $schedule->saved_report_id,
                'organization_id' => $schedule->organization_id,
                'error' => $e->getMessage(),
            ]);

            return 'failed';
        }

        foreach ($recipients as $email) {
            Mail::to($email)->queue(new ScheduledReportEmail(
                (int) $schedule->organization_id,
                $report->name,
                $schedule->frequency,
                $rendered->filename,
                $rendered->mimeType,
                base64_encode($rendered->content),
            ));
        }

        return 'sent';
    }

    /**
     * The schedule's recipients that are still users of its organization,
     * matched case-insensitively and returned as the stored user email.
     *
     * @return array<int, string>
     */
    private function currentRecipients(ReportSchedule $schedule): array
    {
        $wanted = array_values(array_unique(array_map(
            fn ($email) => mb_strtolower(trim((string) $email)),
            $schedule->recipients ?? []
        )));

        if ($wanted === []) {
            return [];
        }

        return User::query()
            ->where('organization_id', $schedule->organization_id)
            ->whereIn(DB::raw('LOWER(email)'), $wanted)
            ->orderBy('id')
            ->pluck('email')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $context
     * @return 'skipped'
     */
    private function skip(ReportSchedule $schedule, string $reason, array $context = []): string
    {
        Log::warning('Scheduled report skipped', $context + [
            'reason' => $reason,
            'schedule_id' => $schedule->id,
            'saved_report_id' => $schedule->saved_report_id,
            'organization_id' => $schedule->organization_id,
        ]);

        return 'skipped';
    }
}
