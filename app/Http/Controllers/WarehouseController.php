<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Warehouse\StoreWarehouseRequest;
use App\Http\Requests\Warehouse\UpdateWarehouseRequest;
use App\Enums\Permission;
use App\Models\Inventory\ProductLocationStock;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\WarehouseAccessService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing warehouses.
 *
 * Handles CRUD operations for warehouses including user assignment,
 * default warehouse management, and active warehouse switching.
 */
class WarehouseController extends Controller
{
    public function __construct(private readonly WarehouseAccessService $warehouseAccess) {}

    /**
     * Display a listing of warehouses.
     */
    public function index(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;

        $warehouses = Warehouse::forOrganization($organizationId)
            ->withCount(['locations', 'users'])
            ->tap(fn ($q) => $this->warehouseAccess->scopeWarehouses($q, $request->user()))
            ->when($request->input('search'), function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('city', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('is_default')
            ->orderByDesc('priority') // higher priority is used first for fulfilment
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Warehouses/Index', [
            'warehouses' => $warehouses,
            'filters' => [
                'search' => $request->input('search', ''),
            ],
            'restrictToAssigned' => $this->warehouseAccess->organizationRestrictsToAssigned((int) $organizationId),
        ]);
    }

    /**
     * Turn the organization's "restrict users to assigned warehouses" policy
     * on or off. When on, users with no warehouse assignment (and without
     * access_all_warehouses) see no warehouse-bound stock at all.
     */
    public function updateAccessPolicy(Request $request)
    {
        $validated = $request->validate([
            'restrict_to_assigned' => ['required', 'boolean'],
        ]);

        $this->warehouseAccess->setOrganizationRestrictsToAssigned(
            (int) $request->user()->organization_id,
            (bool) $validated['restrict_to_assigned'],
        );

        return redirect()->back()->with('success', 'Warehouse access policy updated.');
    }

    /**
     * Show the form for creating a new warehouse.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('Warehouses/Create');
    }

    /**
     * Store a newly created warehouse.
     */
    public function store(StoreWarehouseRequest $request)
    {
        $organizationId = $request->user()->organization_id;

        $validated = $request->validated();

        $validated['organization_id'] = $organizationId;
        $validated['is_active'] = $validated['is_active'] ?? true;

        // If this is the first warehouse for the org, set it as default
        $existingCount = Warehouse::forOrganization($organizationId)->count();
        if ($existingCount === 0) {
            $validated['is_default'] = true;
        }

        $warehouse = Warehouse::create($validated);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'warehouse' => $warehouse,
                'message' => 'Warehouse created successfully.',
            ]);
        }

        return redirect()->route('warehouses.show', $warehouse)
            ->with('success', 'Warehouse created successfully.');
    }

    /**
     * Display the specified warehouse with its locations and assigned users.
     */
    public function show(Request $request, Warehouse $warehouse): Response
    {
        if ($warehouse->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        $this->warehouseAccess->authorizeWarehouse($request->user(), $warehouse->id);

        // On-hand per location = the sum of the product bins held there.
        $onHandByLocation = ProductLocationStock::query()
            ->whereIn('location_id', $warehouse->locations()->pluck('id'))
            ->groupBy('location_id')
            ->selectRaw('location_id, SUM(quantity) as on_hand')
            ->pluck('on_hand', 'location_id');

        $locations = $warehouse->locations()
            ->withCount('products')
            ->orderBy('name')
            ->get()
            ->map(function ($location) use ($onHandByLocation) {
                $location->on_hand = (int) ($onHandByLocation[$location->id] ?? 0);
                $location->utilisation = $this->utilisation($location->on_hand, $location->capacity);

                return $location;
            });

        // The warehouse's own capacity, else the sum of its locations'
        // capacities when any are set. Null means utilisation is unknown.
        $onHand = (int) $onHandByLocation->sum();
        $capacity = $warehouse->capacity
            ?? ($locations->contains(fn ($location) => $location->capacity !== null)
                ? (int) $locations->sum(fn ($location) => (int) $location->capacity)
                : null);

        $assignedUsers = $warehouse->users()
            ->select(['users.id', 'users.name', 'users.email'])
            ->orderBy('users.name')
            ->get();

        return Inertia::render('Warehouses/Show', [
            'warehouse' => $warehouse,
            'locations' => $locations,
            'assignedUsers' => $assignedUsers,
            'stats' => [
                'locations_count' => $locations->count(),
                'products_count' => ProductLocationStock::query()
                    ->whereIn('location_id', $locations->pluck('id'))
                    ->where('quantity', '>', 0)
                    ->distinct()
                    ->count('product_id'),
                'on_hand' => $onHand,
                'capacity' => $capacity,
                'utilisation' => $this->utilisation($onHand, $capacity),
            ],
        ]);
    }

    /**
     * Percentage of capacity in use, rounded; null when there is no capacity.
     */
    private function utilisation(int $onHand, ?int $capacity): ?int
    {
        if ($capacity === null || $capacity <= 0) {
            return null;
        }

        return (int) round($onHand / $capacity * 100);
    }

    /**
     * Show the form for editing the specified warehouse.
     */
    public function edit(Request $request, Warehouse $warehouse): Response
    {
        if ($warehouse->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        $this->warehouseAccess->authorizeWarehouse($request->user(), $warehouse->id);

        // Every org user, flagged when an assignment would not restrict them
        // (admins and access_all_warehouses holders see every warehouse).
        $users = User::where('organization_id', $request->user()->organization_id)
            ->with('roles')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'has_all_warehouse_access' => $user->isAdmin() || $user->hasPermission(Permission::ACCESS_ALL_WAREHOUSES),
            ])
            ->values();

        return Inertia::render('Warehouses/Edit', [
            'warehouse' => $warehouse,
            'users' => $users,
            'assignedUserIds' => $warehouse->users()->pluck('users.id')->map(fn ($id) => (int) $id)->values(),
        ]);
    }

    /**
     * Update the specified warehouse.
     */
    public function update(UpdateWarehouseRequest $request, Warehouse $warehouse)
    {
        if ($warehouse->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        $this->warehouseAccess->authorizeWarehouse($request->user(), $warehouse->id);

        $validated = $request->validated();

        $warehouse->update($validated);

        return redirect()->route('warehouses.show', $warehouse)
            ->with('success', 'Warehouse updated successfully.');
    }

    /**
     * Remove the specified warehouse.
     */
    public function destroy(Request $request, Warehouse $warehouse)
    {
        if ($warehouse->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        $this->warehouseAccess->authorizeWarehouse($request->user(), $warehouse->id);

        if ($warehouse->is_default) {
            return redirect()->back()
                ->withErrors(['warehouse' => 'Cannot delete the default warehouse. Set another warehouse as default first.']);
        }

        // Check if warehouse has locations with products
        if ($warehouse->locations()->whereHas('products')->exists()) {
            return redirect()->back()
                ->withErrors(['warehouse' => 'Cannot delete warehouse with locations that have associated products.']);
        }

        $warehouse->delete();

        return redirect()->route('warehouses.index')
            ->with('success', 'Warehouse deleted successfully.');
    }

    /**
     * Sync user assignments to the warehouse.
     */
    public function updateUsers(Request $request, Warehouse $warehouse)
    {
        if ($warehouse->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        $this->warehouseAccess->authorizeWarehouse($request->user(), $warehouse->id);

        // 'present' (not 'required') so an empty list clears every assignment.
        $validated = $request->validate([
            'user_ids' => ['present', 'array'],
            'user_ids.*' => ['integer', Rule::exists('users', 'id')->where('organization_id', $request->user()->organization_id)],
        ]);

        $warehouse->users()->sync($validated['user_ids']);

        return redirect()->back()
            ->with('success', 'Warehouse user assignments updated successfully.');
    }

    /**
     * Set the specified warehouse as the organization default.
     */
    public function setDefault(Request $request, Warehouse $warehouse)
    {
        if ($warehouse->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        $this->warehouseAccess->authorizeWarehouse($request->user(), $warehouse->id);

        $organizationId = $request->user()->organization_id;

        // Remove default from all other warehouses in this org
        Warehouse::forOrganization($organizationId)
            ->where('is_default', true)
            ->update(['is_default' => false]);

        $warehouse->update(['is_default' => true]);

        return redirect()->back()
            ->with('success', 'Default warehouse updated successfully.');
    }

    /**
     * Set the user's active warehouse in session.
     */
    public function setActiveWarehouse(Request $request)
    {
        $validated = $request->validate([
            'warehouse_id' => ['nullable', 'integer'],
        ]);

        $warehouseId = $validated['warehouse_id'] ?? null;

        if ($warehouseId !== null) {
            // Verify user has access to this warehouse
            if (! $request->user()->hasWarehouseAccess($warehouseId)) {
                abort(403, 'You do not have access to this warehouse.');
            }

            // Verify warehouse belongs to user's org
            $exists = Warehouse::forOrganization($request->user()->organization_id)
                ->where('id', $warehouseId)
                ->active()
                ->exists();

            if (! $exists) {
                abort(404, 'Warehouse not found.');
            }
        }

        session(['active_warehouse_id' => $warehouseId]);

        return redirect()->back()
            ->with('success', $warehouseId ? 'Active warehouse changed.' : 'Viewing all warehouses.');
    }
}
