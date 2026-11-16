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
use App\Rules\OrganizationMember;
use App\Services\CycleCountService;
use App\Services\WarehouseAccessService;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
    public function __construct(private readonly WarehouseAccessService $warehouseAccess) {}

    public function index(Request $request): Response
    {
        $schedules = CycleCountSchedule::with(['assignee:id,name', 'lastAudit:id,audit_number,status'])
            ->tap(fn (Builder $query) => $this->scopeToAccessibleWarehouses($query, $request->user()))
            ->orderBy('name')
            ->get();

        $options = $this->options($request->user());

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
        ] + $this->options($request->user()));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);
        $this->validateScopeAccess($request->user(), $validated['scope_type'], $validated['scope_id'] ?? null);

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

        return redirect()->route('cycle-counts.index')->with('success', __('Cycle count schedule created.'));
    }

    public function edit(Request $request, CycleCountSchedule $cycleCount): Response
    {
        $this->authorizeSchedule($request->user(), $cycleCount);

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
        ] + $this->options($request->user()));
    }

    public function update(Request $request, CycleCountSchedule $cycleCount): RedirectResponse
    {
        $this->authorizeSchedule($request->user(), $cycleCount);
        $validated = $this->validated($request);
        $this->validateScopeAccess($request->user(), $validated['scope_type'], $validated['scope_id'] ?? null);

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

        return redirect()->route('cycle-counts.index')->with('success', __('Cycle count schedule updated.'));
    }

    public function destroy(Request $request, CycleCountSchedule $cycleCount): RedirectResponse
    {
        $this->authorizeSchedule($request->user(), $cycleCount);

        $cycleCount->delete();

        return redirect()->route('cycle-counts.index')->with('success', __('Cycle count schedule deleted.'));
    }

    /**
     * Create this schedule's audit now, leaving its regular run time alone.
     */
    public function run(Request $request, CycleCountSchedule $cycleCount, CycleCountService $cycleCounts): RedirectResponse
    {
        $this->authorizeSchedule($request->user(), $cycleCount);

        $audit = $cycleCounts->run($cycleCount, advance: false);

        if (! $audit) {
            return redirect()->back()->with('error', __('No audit was created: the last one from this schedule is still open, or nothing is in scope.'));
        }

        return redirect()->route('stock-audits.show', $audit)
            ->with('success', "Cycle count {$audit->audit_number} created with {$audit->items()->count()} product(s).");
    }

    /**
     * @throws AuthorizationException
     */
    private function authorizeSchedule(User $user, CycleCountSchedule $schedule): void
    {
        $this->authorizeScope($user, (string) $schedule->scope_type, $schedule->scope_id !== null ? (int) $schedule->scope_id : null);
    }

    /**
     * A warehouse or location scope needs access to that warehouse. Category
     * and whole-organization scopes count products in every warehouse
     * (CycleCountService does not narrow them by location), so only
     * unrestricted users may manage them.
     *
     * @throws AuthorizationException
     */
    private function authorizeScope(User $user, string $scopeType, int|string|null $scopeId): void
    {
        $scopeId = $scopeId !== null ? (int) $scopeId : null;

        match ($scopeType) {
            'warehouse' => $this->warehouseAccess->authorizeWarehouse($user, $scopeId),
            'location' => $this->warehouseAccess->authorizeLocation($user, $scopeId),
            default => $this->warehouseAccess->isRestricted($user)
                ? throw new AuthorizationException('You do not have access to every warehouse.')
                : null,
        };
    }

    /**
     * The requested scope of a store or update, refused as a field error so
     * the form can show it.
     *
     * @throws ValidationException
     */
    private function validateScopeAccess(User $user, string $scopeType, int|string|null $scopeId): void
    {
        $scopeId = $scopeId !== null ? (int) $scopeId : null;

        $allowed = match ($scopeType) {
            'warehouse' => $this->warehouseAccess->canAccessWarehouse($user, $scopeId),
            'location' => $this->warehouseAccess->canAccessLocation($user, $scopeId),
            default => ! $this->warehouseAccess->isRestricted($user),
        };

        if ($allowed) {
            return;
        }

        throw in_array($scopeType, ['warehouse', 'location'], true)
            ? ValidationException::withMessages(['scope_id' => __('Choose a warehouse or location you have access to.')])
            : ValidationException::withMessages(['scope_type' => __('Only users with access to every warehouse can schedule counts across all warehouses or by category.')]);
    }

    /**
     * @param  Builder<CycleCountSchedule>  $query
     */
    private function scopeToAccessibleWarehouses(Builder $query, User $user): void
    {
        $ids = $this->warehouseAccess->accessibleWarehouseIds($user);

        if ($ids === null) {
            return;
        }

        $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $w) => $w->where('scope_type', 'warehouse')->whereIn('scope_id', $ids))
            ->orWhere(fn (Builder $l) => $this->warehouseAccess->scopeByLocation($l->where('scope_type', 'location'), $user, 'scope_id')));
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
            'assigned_to' => ['nullable', 'integer', new OrganizationMember($orgId)],
            'is_active' => ['sometimes', 'boolean'],
            'next_run_at' => ['nullable', 'date'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function options(User $user): array
    {
        $organizationId = (int) $user->organization_id;
        // Category and whole-organization scopes span every warehouse.
        $restricted = $this->warehouseAccess->isRestricted($user);

        return [
            'locations' => $this->warehouseAccess->scopeLocations(ProductLocation::forOrganization($organizationId)->active(), $user)
                ->orderBy('name')->get(['id', 'name', 'code']),
            'warehouses' => $this->warehouseAccess->accessibleWarehousesQuery($user)->orderBy('name')->get(['id', 'name', 'code']),
            'categories' => $restricted ? collect() : ProductCategory::where('organization_id', $organizationId)->orderBy('name')->get(['id', 'name']),
            'users' => User::query()->memberOf($organizationId)->orderBy('name')->get(['id', 'name']),
            'frequencies' => CycleCountSchedule::FREQUENCIES,
            'scopes' => $restricted
                ? array_values(array_intersect(CycleCountSchedule::SCOPES, ['location', 'warehouse']))
                : CycleCountSchedule::SCOPES,
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
