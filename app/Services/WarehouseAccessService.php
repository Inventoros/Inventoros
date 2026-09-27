<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Permission;
use App\Models\Inventory\ProductLocation;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Purchasing\PurchaseOrderItem;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The single place that decides which warehouses a user may see and act on.
 *
 * Every surface that touches warehouse-bound stock (web, REST, GraphQL, MCP)
 * asks this service instead of re-deriving the rule, so the rule lives here:
 *
 *  - Admins and users holding `access_all_warehouses` are never restricted.
 *  - A user assigned to one or more warehouses (warehouse_user) is restricted
 *    to those warehouses.
 *  - A user with no assignments keeps the historic behaviour (every warehouse
 *    in the organization) unless the organization turned on
 *    "restrict users to assigned warehouses", in which case they see none.
 *
 * Locations that belong to no warehouse are only visible to unrestricted
 * users: a restricted user's world is the set of warehouses they were given.
 */
final class WarehouseAccessService
{
    /**
     * Organization setting key. Off by default so upgrading never locks
     * anyone out: only explicit assignments narrow what a user sees.
     */
    public const RESTRICT_SETTING = 'warehouses.restrict_to_assigned';

    /**
     * Whether the organization restricts unassigned users to no warehouses.
     */
    public function organizationRestrictsToAssigned(int $organizationId): bool
    {
        return (bool) SettingsService::get(self::RESTRICT_SETTING, false, $organizationId);
    }

    public function setOrganizationRestrictsToAssigned(int $organizationId, bool $restrict): void
    {
        Setting::updateOrCreate(
            ['organization_id' => $organizationId, 'key' => self::RESTRICT_SETTING],
            ['value' => $restrict ? '1' : '0', 'encrypted' => false],
        );

        Cache::forget("settings.{$organizationId}.".self::RESTRICT_SETTING);
    }

    /**
     * Whether the user's warehouse access is narrowed at all.
     */
    public function isRestricted(User $user): bool
    {
        return $this->accessibleWarehouseIds($user) !== null;
    }

    /**
     * The warehouse ids the user may access, or null when unrestricted.
     *
     * @return array<int, int>|null
     */
    public function accessibleWarehouseIds(User $user): ?array
    {
        if ($user->isAdmin() || $user->hasPermission(Permission::ACCESS_ALL_WAREHOUSES)) {
            return null;
        }

        $assigned = DB::table('warehouse_user')
            ->join('warehouses', 'warehouses.id', '=', 'warehouse_user.warehouse_id')
            ->where('warehouse_user.user_id', $user->id)
            ->where('warehouses.organization_id', $user->organization_id)
            ->whereNull('warehouses.deleted_at')
            ->pluck('warehouses.id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($assigned !== []) {
            return $assigned;
        }

        if ($user->organization_id !== null && $this->organizationRestrictsToAssigned((int) $user->organization_id)) {
            return [];
        }

        return null;
    }

    public function canAccessWarehouse(User $user, ?int $warehouseId): bool
    {
        $ids = $this->accessibleWarehouseIds($user);

        if ($ids === null) {
            return true;
        }

        return $warehouseId !== null && in_array($warehouseId, $ids, true);
    }

    /**
     * Whether the user may see or act on stock at a location. A null location
     * (stock not bound to any place) is only reachable when unrestricted.
     */
    public function canAccessLocation(User $user, ProductLocation|int|null $location): bool
    {
        $ids = $this->accessibleWarehouseIds($user);

        if ($ids === null) {
            return true;
        }

        if ($location === null) {
            return false;
        }

        $warehouseId = $location instanceof ProductLocation
            ? $location->warehouse_id
            : ProductLocation::withoutGlobalScopes()->whereKey($location)->value('warehouse_id');

        return $warehouseId !== null && in_array((int) $warehouseId, $ids, true);
    }

    /**
     * @throws AuthorizationException
     */
    public function authorizeWarehouse(User $user, ?int $warehouseId): void
    {
        if (! $this->canAccessWarehouse($user, $warehouseId)) {
            throw new AuthorizationException('You do not have access to this warehouse.');
        }
    }

    /**
     * @throws AuthorizationException
     */
    public function authorizeLocation(User $user, ProductLocation|int|null $location): void
    {
        if (! $this->canAccessLocation($user, $location)) {
            throw new AuthorizationException('You do not have access to this location\'s warehouse.');
        }
    }

    /**
     * Passes when ANY of the locations is accessible: a transfer is visible
     * to, and actionable by, either end of the move.
     *
     * @param  array<int, ProductLocation|int|null>  $locations
     *
     * @throws AuthorizationException
     */
    public function authorizeAnyLocation(User $user, array $locations): void
    {
        foreach ($locations as $location) {
            if ($this->canAccessLocation($user, $location)) {
                return;
            }
        }

        throw new AuthorizationException('You do not have access to this location\'s warehouse.');
    }

    /**
     * Receiving books each line into its product's primary location, so a
     * restricted user may only receive lines that land in their warehouses.
     *
     * @param  array<int, array{id: int|string, quantity_to_receive: int|string}>  $items
     *
     * @throws AuthorizationException
     */
    public function authorizeReceiving(User $user, PurchaseOrder $purchaseOrder, array $items): void
    {
        if (! $this->isRestricted($user)) {
            return;
        }

        $itemIds = collect($items)
            ->filter(fn ($item) => (int) ($item['quantity_to_receive'] ?? 0) > 0)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $lines = PurchaseOrderItem::query()
            ->with('product:id,location_id')
            ->where('purchase_order_id', $purchaseOrder->id)
            ->whereIn('id', $itemIds)
            ->get();

        foreach ($lines as $line) {
            $this->authorizeLocation($user, $line->product?->location_id);
        }
    }

    /**
     * Narrow a warehouses query to the user's warehouses.
     *
     * @template TBuilder of Builder|QueryBuilderContract
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function scopeWarehouses($query, User $user, string $column = 'warehouses.id')
    {
        $ids = $this->accessibleWarehouseIds($user);

        if ($ids !== null) {
            $query->whereIn($column, $ids);
        }

        return $query;
    }

    /**
     * Narrow a product_locations query (or anything carrying a warehouse_id
     * column) to the user's warehouses.
     *
     * @template TBuilder of Builder|QueryBuilderContract
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function scopeLocations($query, User $user, string $warehouseColumn = 'warehouse_id')
    {
        $ids = $this->accessibleWarehouseIds($user);

        if ($ids !== null) {
            $query->whereIn($warehouseColumn, $ids);
        }

        return $query;
    }

    /**
     * Narrow a query to rows whose location column points at a location in
     * one of the user's warehouses (stock bins, audits, adjustments).
     *
     * @template TBuilder of Builder|QueryBuilderContract
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function scopeByLocation($query, User $user, string $locationColumn)
    {
        $ids = $this->accessibleWarehouseIds($user);

        if ($ids !== null) {
            $query->whereIn($locationColumn, $this->accessibleLocationIdsSubquery($ids));
        }

        return $query;
    }

    /**
     * Narrow a query to rows where ANY of the given location columns is
     * accessible (a transfer is visible from either end).
     *
     * @template TBuilder of Builder|QueryBuilderContract
     *
     * @param  TBuilder  $query
     * @param  array<int, string>  $locationColumns
     * @return TBuilder
     */
    public function scopeByAnyLocation($query, User $user, array $locationColumns)
    {
        $ids = $this->accessibleWarehouseIds($user);

        if ($ids !== null) {
            $query->where(function ($q) use ($locationColumns, $ids) {
                foreach ($locationColumns as $column) {
                    $q->orWhereIn($column, $this->accessibleLocationIdsSubquery($ids));
                }
            });
        }

        return $query;
    }

    /**
     * @param  array<int, int>  $warehouseIds
     */
    private function accessibleLocationIdsSubquery(array $warehouseIds): \Illuminate\Database\Query\Builder
    {
        return DB::table('product_locations')
            ->select('id')
            ->whereIn('warehouse_id', $warehouseIds);
    }

    /**
     * Warehouses the user can pick from in the switcher and in forms.
     *
     * @return Builder<Warehouse>
     */
    public function accessibleWarehousesQuery(User $user): Builder
    {
        return $this->scopeWarehouses(
            Warehouse::query()->where('warehouses.organization_id', $user->organization_id),
            $user,
        );
    }
}
