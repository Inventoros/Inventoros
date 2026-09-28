<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\Permission;
use App\Jobs\DeliverScheduledReportJob;
use App\Mail\ScheduledReportEmail;
use App\Models\ReportSchedule;
use App\Models\SavedReport;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Services\ReportDataService;
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
 * view_reports and the data source's own view permission (re-checked again
 * by ReportDataService::executeReport when it renders). If any check fails
 * the delivery is skipped and logged. Recipients are re-filtered to current
 * members of the organization who may still see the report, so someone who
 * has left or lost access stops receiving it.
 *
 * The report itself is generated inside DeliverScheduledReportJob and mailed
 * from there, so its contents are never serialized into the jobs (or
 * failed_jobs) table: the queued payload carries only ids and addresses.
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
                    $stats[$this->send($schedule)]++;
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
     * Check the schedule can go out and queue its delivery. 'sent' means the
     * delivery job was queued; the job records 'failed' or 'skipped' itself
     * if rendering or mailing then fails.
     *
     * @return 'sent'|'skipped'|'failed'
     */
    public function send(ReportSchedule $schedule): string
    {
        [$report, $owner, $skip] = $this->resolveOwner($schedule);
        if ($skip !== null) {
            return $this->record($schedule, $skip);
        }

        $recipients = $this->currentRecipients($schedule, $report->data_source);
        if ($recipients === []) {
            return $this->record($schedule, $this->skip($schedule, 'no_recipients'));
        }

        $this->record($schedule, 'sent');
        DeliverScheduledReportJob::dispatch($schedule->id, $recipients);

        return 'sent';
    }

    /**
     * Generate the report and mail it to $recipients. Runs in the queue
     * worker (DeliverScheduledReportJob); the rendered file only ever lives
     * in memory here and in the synchronously sent mail.
     *
     * @param  array<int, string>  $recipients
     * @return 'sent'|'skipped'|'failed'
     */
    public function deliver(int $scheduleId, array $recipients): string
    {
        $schedule = ReportSchedule::query()->withoutGlobalScope(OrganizationScope::class)->find($scheduleId);
        if ($schedule === null) {
            return 'skipped';
        }

        [$report, $owner, $skip] = $this->resolveOwner($schedule);
        if ($skip !== null) {
            return $this->record($schedule, $skip);
        }

        try {
            $rendered = $this->renderer->render($report, $owner, $schedule->format);
        } catch (AuthorizationException $e) {
            return $this->record($schedule, $this->skip($schedule, 'permission_revoked', ['data_source' => $report->data_source]));
        } catch (\Throwable $e) {
            Log::error('Scheduled report failed to render', [
                'schedule_id' => $schedule->id,
                'saved_report_id' => $schedule->saved_report_id,
                'organization_id' => $schedule->organization_id,
                'error' => $e->getMessage(),
            ]);

            return $this->record($schedule, 'failed');
        }

        $status = 'sent';
        foreach ($recipients as $email) {
            try {
                // send(), not queue(): the attachment must not be serialized.
                Mail::to($email)->send(new ScheduledReportEmail(
                    (int) $schedule->organization_id,
                    $report->name,
                    $schedule->frequency,
                    $rendered->filename,
                    $rendered->mimeType,
                    $rendered->content,
                ));
            } catch (\Throwable $e) {
                $status = 'failed';
                Log::error('Scheduled report could not be mailed', [
                    'schedule_id' => $schedule->id,
                    'organization_id' => $schedule->organization_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->record($schedule, $status);
    }

    /**
     * The saved report and its owner, or the skip status when the owner can
     * no longer run it.
     *
     * @return array{0: SavedReport|null, 1: User|null, 2: 'skipped'|null}
     */
    private function resolveOwner(ReportSchedule $schedule): array
    {
        $report = $schedule->savedReport()->withoutGlobalScope(OrganizationScope::class)->first();
        $owner = $report !== null ? User::query()->find($report->created_by) : null;

        if ($report === null || $owner === null
            || (int) $report->organization_id !== (int) $schedule->organization_id
            || (int) $owner->organization_id !== (int) $schedule->organization_id) {
            return [$report, $owner, $this->skip($schedule, 'owner_unavailable')];
        }

        if (! $owner->hasPermission(Permission::VIEW_REPORTS)) {
            return [$report, $owner, $this->skip($schedule, 'permission_revoked', ['permission' => Permission::VIEW_REPORTS->value])];
        }

        if (! $owner->hasAllPermissions(ReportDataService::viewPermissionsFor((string) $report->data_source))) {
            return [$report, $owner, $this->skip($schedule, 'permission_revoked', ['data_source' => $report->data_source])];
        }

        return [$report, $owner, null];
    }

    /**
     * @param  'sent'|'skipped'|'failed'  $status
     * @return 'sent'|'skipped'|'failed'
     */
    private function record(ReportSchedule $schedule, string $status): string
    {
        ReportSchedule::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->whereKey($schedule->id)
            ->update(['last_status' => $status]);

        return $status;
    }

    /**
     * The schedule's recipients that are still users of its organization and
     * may themselves see the report (view_reports plus the data source's view
     * permission), matched case-insensitively and returned as the stored
     * user email. The report is generated with the owner's access, so without
     * this an owner could mail data to colleagues who may not see it.
     *
     * @return array<int, string>
     */
    private function currentRecipients(ReportSchedule $schedule, string $dataSource): array
    {
        $required = ReportDataService::viewPermissionsFor($dataSource);

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
            ->get()
            ->filter(fn (User $user): bool => $user->hasAllPermissions($required))
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
