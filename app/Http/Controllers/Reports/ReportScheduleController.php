<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\ReportSchedule;
use App\Models\SavedReport;
use App\Models\User;
use App\Services\ReportDataService;
use App\Services\Reports\ReportExporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Manage the email delivery schedules of a saved report.
 *
 * Only the report's owner may manage its schedules: the report is sent with
 * the owner's permissions, so nobody else may point it at recipients.
 * Recipients must be users of the same organization who may view the report
 * themselves (view_reports plus the data source's view permission).
 */
class ReportScheduleController extends Controller
{
    public function store(Request $request, SavedReport $savedReport): RedirectResponse
    {
        $this->authorizeOwner($request, $savedReport);

        $validated = $this->validated($request, $savedReport);

        $schedule = new ReportSchedule($validated + [
            'organization_id' => $savedReport->organization_id,
            'saved_report_id' => $savedReport->id,
            'created_by' => $request->user()->id,
            'is_active' => true,
        ]);
        $schedule->next_run_at = $schedule->computeNextRunAt(now());
        $schedule->save();

        return back()->with('success', 'Report schedule created.');
    }

    public function update(Request $request, SavedReport $savedReport, ReportSchedule $schedule): RedirectResponse
    {
        $this->authorizeOwner($request, $savedReport);
        $this->ensureBelongs($savedReport, $schedule);

        $validated = $this->validated($request, $savedReport) + [
            'is_active' => $request->boolean('is_active', $schedule->is_active),
        ];

        $schedule->fill($validated);
        // Re-anchor from now whenever the timing changes or it is resumed, so
        // a paused schedule does not fire immediately for a missed slot.
        $schedule->next_run_at = $schedule->computeNextRunAt(now());
        $schedule->save();

        return back()->with('success', 'Report schedule updated.');
    }

    public function destroy(Request $request, SavedReport $savedReport, ReportSchedule $schedule): RedirectResponse
    {
        $this->authorizeOwner($request, $savedReport);
        $this->ensureBelongs($savedReport, $schedule);

        $schedule->delete();

        return back()->with('success', 'Report schedule deleted.');
    }

    private function authorizeOwner(Request $request, SavedReport $savedReport): void
    {
        $user = $request->user();

        if ($savedReport->organization_id !== $user->organization_id || $savedReport->created_by !== $user->id) {
            abort(403);
        }
    }

    private function ensureBelongs(SavedReport $savedReport, ReportSchedule $schedule): void
    {
        // Route binding already applies OrganizationScope; this pins the
        // schedule to the report named in the URL as well.
        if ($schedule->saved_report_id !== $savedReport->id) {
            abort(404);
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validated(Request $request, SavedReport $savedReport): array
    {
        $validated = $request->validate([
            'frequency' => ['required', 'string', Rule::in(ReportSchedule::FREQUENCIES)],
            'day_of_week' => ['nullable', 'required_if:frequency,weekly', 'integer', 'between:0,6'],
            'day_of_month' => ['nullable', 'required_if:frequency,monthly', 'integer', 'between:1,31'],
            'time_of_day' => ['required', 'date_format:H:i'],
            'format' => ['required', 'string', Rule::in(ReportExporter::FORMATS)],
            'recipients' => ['required', 'array', 'min:1', 'max:'.ReportSchedule::MAX_RECIPIENTS],
            'recipients.*' => ['required', 'email', 'max:255'],
        ]);

        $recipients = array_values(array_unique(array_map(
            fn ($email) => mb_strtolower(trim((string) $email)),
            $validated['recipients']
        )));

        $members = User::query()
            ->where('organization_id', $request->user()->organization_id)
            ->whereIn(DB::raw('LOWER(email)'), $recipients)
            ->get();

        $outsiders = array_diff($recipients, $members->map(fn (User $u) => mb_strtolower((string) $u->email))->all());
        if ($outsiders !== []) {
            throw ValidationException::withMessages([
                'recipients' => 'Recipients must be members of your organization: '.implode(', ', $outsiders),
            ]);
        }

        // The report is sent with the owner's access, so each recipient must
        // be allowed to see it themselves (re-checked at send time too).
        $required = ReportDataService::viewPermissionsFor((string) $savedReport->data_source);
        $withoutAccess = $members
            ->reject(fn (User $u): bool => $u->hasAllPermissions($required))
            ->map(fn (User $u) => mb_strtolower((string) $u->email))
            ->values()
            ->all();
        if ($withoutAccess !== []) {
            throw ValidationException::withMessages([
                'recipients' => 'Recipients must be able to view this report ('.implode(', ', $required).'): '.implode(', ', $withoutAccess),
            ]);
        }

        return [
            'frequency' => $validated['frequency'],
            'day_of_week' => $validated['frequency'] === 'weekly' ? (int) $validated['day_of_week'] : null,
            'day_of_month' => $validated['frequency'] === 'monthly' ? (int) $validated['day_of_month'] : null,
            'time_of_day' => $validated['time_of_day'],
            'format' => $validated['format'],
            'recipients' => $recipients,
        ];
    }
}
