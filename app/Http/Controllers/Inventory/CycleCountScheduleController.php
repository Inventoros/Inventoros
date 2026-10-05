<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Inventory\CycleCountSchedule;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductLocation;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CycleCountService;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manage scheduled cycle counts (under Stock Audits).
 *
 * The scheduler (inventory:run-cycle-counts) turns due schedules into draft
 * cycle audits; "Run now" does the same on demand without moving the
 * regular schedule.
 */
class CycleCountScheduleController extends Controller
{
    public function index(Request $request): Response
    {
        $schedules = CycleCountSchedule::with(['assignee:id,name', 'lastAudit:id,audit_number,status'])
            ->orderBy('name')
            ->get();

        $options = $this->options($request->user()->organization_id);

        return Inertia::render('StockAudits/CycleCounts/Index', [
            'pluginComponents' => plugin_slots('cycle-counts.index', ['header', 'footer']),
            'schedules' => $schedules->map(fn (CycleCountSchedule $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'frequency' => $s->frequency,
                'scope_type' => $s->scope_type,
                'scope_id' => $s->scope_id,
                'scope_label' => $this->scopeLabel($s, $options),
                'products_per_run' => $s->products_per_run,
                'assignee' => $s->assignee?->name,
                'is_active' => $s->is_active,
                'next_run_at' => $s->next_run_at?->toIso8601String(),
                'last_run_at' => $s->last_run_at?->toIso8601String(),
                'last_audit' => $s->lastAudit ? [
                    'id' => $s->lastAudit->id,
                    'audit_number' => $s->lastAudit->audit_number,
                    'status' => $s->lastAudit->status,
                ] : null,
            ])->values(),
            'canManage' => $request->user()->hasPermission('manage_stock_audits'),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('StockAudits/CycleCounts/Form', [
            'schedule' => null,
        ] + $this->options($request->user()->organization_id));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);

        CycleCountSchedule::create([
            'organization_id' => $request->user()->organization_id,
            'name' => $validated['name'],
            'frequency' => $validated['frequency'],
            'scope_type' => $validated['scope_type'],
            'scope_id' => $validated['scope_type'] === 'all' ? null : $validated['scope_id'],
            'products_per_run' => $validated['products_per_run'],
            'assigned_to' => $validated['assigned_to'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            'next_run_at' => isset($validated['next_run_at']) ? Carbon::parse($validated['next_run_at']) : now(),
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('cycle-counts.index')->with('success', 'Cycle count schedule created.');
    }

    public function edit(Request $request, CycleCountSchedule $cycleCount): Response
    {
        return Inertia::render('StockAudits/CycleCounts/Form', [
            'schedule' => [
                'id' => $cycleCount->id,
                'name' => $cycleCount->name,
                'frequency' => $cycleCount->frequency,
                'scope_type' => $cycleCount->scope_type,
                'scope_id' => $cycleCount->scope_id,
                'products_per_run' => $cycleCount->products_per_run,
                'assigned_to' => $cycleCount->assigned_to,
                'is_active' => $cycleCount->is_active,
                'next_run_at' => $cycleCount->next_run_at?->format('Y-m-d\TH:i'),
            ],
        ] + $this->options($request->user()->organization_id));
    }

    public function update(Request $request, CycleCountSchedule $cycleCount): RedirectResponse
    {
        $validated = $this->validated($request);

        $cycleCount->update([
            'name' => $validated['name'],
            'frequency' => $validated['frequency'],
            'scope_type' => $validated['scope_type'],
            'scope_id' => $validated['scope_type'] === 'all' ? null : $validated['scope_id'],
            'products_per_run' => $validated['products_per_run'],
            'assigned_to' => $validated['assigned_to'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            'next_run_at' => isset($validated['next_run_at'])
                ? Carbon::parse($validated['next_run_at'])
                : ($cycleCount->next_run_at ?? now()),
        ]);

        return redirect()->route('cycle-counts.index')->with('success', 'Cycle count schedule updated.');
    }

    public function destroy(CycleCountSchedule $cycleCount): RedirectResponse
    {
        $cycleCount->delete();

        return redirect()->route('cycle-counts.index')->with('success', 'Cycle count schedule deleted.');
    }

    /**
     * Create this schedule's audit now, leaving its regular run time alone.
     */
    public function run(CycleCountSchedule $cycleCount, CycleCountService $cycleCounts): RedirectResponse
    {
        $audit = $cycleCounts->run($cycleCount, advance: false);

        if (! $audit) {
            return redirect()->back()->with('error', 'No audit was created: the last one from this schedule is still open, or nothing is in scope.');
        }

        return redirect()->route('stock-audits.show', $audit)
            ->with('success', "Cycle count {$audit->audit_number} created with {$audit->items()->count()} product(s).");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $orgId = $request->user()->organization_id;

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'frequency' => ['required', Rule::in(CycleCountSchedule::FREQUENCIES)],
            'scope_type' => ['required', Rule::in(CycleCountSchedule::SCOPES)],
            'scope_id' => [
                'nullable',
                'required_unless:scope_type,all',
                'integer',
                function (string $attribute, mixed $value, Closure $fail) use ($request, $orgId) {
                    if ($value === null || $request->input('scope_type') === 'all') {
                        return;
                    }

                    $model = match ($request->input('scope_type')) {
                        'location' => ProductLocation::class,
                        'warehouse' => Warehouse::class,
                        'category' => ProductCategory::class,
                        default => null,
                    };

                    if ($model && ! $model::query()->withoutGlobalScope(OrganizationScope::class)->where('organization_id', $orgId)->whereKey($value)->exists()) {
                        $fail('Choose a location, warehouse or category from your organization.');
                    }
                },
            ],
            'products_per_run' => ['required', 'integer', 'min:1', 'max:1000'],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'is_active' => ['sometimes', 'boolean'],
            'next_run_at' => ['nullable', 'date'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function options(int $organizationId): array
    {
        return [
            'locations' => ProductLocation::forOrganization($organizationId)->active()->orderBy('name')->get(['id', 'name', 'code']),
            'warehouses' => Warehouse::where('organization_id', $organizationId)->orderBy('name')->get(['id', 'name', 'code']),
            'categories' => ProductCategory::where('organization_id', $organizationId)->orderBy('name')->get(['id', 'name']),
            'users' => User::where('organization_id', $organizationId)->orderBy('name')->get(['id', 'name']),
            'frequencies' => CycleCountSchedule::FREQUENCIES,
            'scopes' => CycleCountSchedule::SCOPES,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function scopeLabel(CycleCountSchedule $schedule, array $options): ?string
    {
        $list = match ($schedule->scope_type) {
            'location' => $options['locations'],
            'warehouse' => $options['warehouses'],
            'category' => $options['categories'],
            default => null,
        };

        return $list?->firstWhere('id', $schedule->scope_id)?->name;
    }
}
