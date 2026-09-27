<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\ReportSchedule;
use App\Models\SavedReport;
use App\Models\User;
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
 * Recipients must be users of the same organization.
 */
class ReportScheduleController extends Controller
{
    public function store(Request $request, SavedReport $savedReport): RedirectResponse
    {
        $this->authorizeOwner($request, $savedReport);

        $validated = $this->validated($request);

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

        $validated = $this->validated($request) + [
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
    private function validated(Request $request): array
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
            ->pluck('email')
            ->map(fn ($email) => mb_strtolower((string) $email))
            ->all();

        $outsiders = array_diff($recipients, $members);
        if ($outsiders !== []) {
            throw ValidationException::withMessages([
                'recipients' => 'Recipients must be members of your organization: '.implode(', ', $outsiders),
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
