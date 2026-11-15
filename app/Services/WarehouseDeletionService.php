<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Permission;
use App\Models\Inventory\ProductLocation;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Organizations\ActiveOrganization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class WarehouseDeletionService
{
    public function delete(Warehouse $warehouse, User $actor): void
    {
        abort_unless((int) $warehouse->organization_id === (int) $actor->organization_id, 403);
        app(WarehouseAccessService::class)->authorizeWarehouse($actor, (int) $warehouse->id);

        DB::transaction(function () use ($warehouse, $actor): void {
            $locked = Warehouse::whereKey($warehouse->id)->lockForUpdate()->firstOrFail();
            if ($locked->is_default) {
                throw ValidationException::withMessages(['warehouse' => 'Cannot delete the default warehouse. Set another warehouse as default first.']);
            }
            $locations = ProductLocation::withTrashed()->where('organization_id', $locked->organization_id)
                ->where('warehouse_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
            $ids = $locations->pluck('id');
            $stock = DB::table('product_location_stocks')->whereIn('location_id', $ids)->where('quantity', '<>', 0)->exists();
            // An unbinned product still holds its stock at its primary location.
            $stock = $stock || DB::table('products as p')->whereIn('p.location_id', $ids)->where('p.stock', '<>', 0)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('product_location_stocks as b')->whereColumn('b.product_id', 'p.id'))
                ->whereRaw('NOT (p.has_variants = TRUE AND EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id AND v.deleted_at IS NULL))')->exists();
            if (Schema::hasTable('variant_location_stocks')) {
                $stock = $stock || DB::table('variant_location_stocks')->whereIn('location_id', $ids)->where('quantity', '<>', 0)->exists();
                $stock = $stock || DB::table('product_variants as v')->join('products as p', 'p.id', '=', 'v.product_id')
                    ->whereIn('p.location_id', $ids)->where('v.stock', '<>', 0)
                    ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('variant_location_stocks as b')->whereColumn('b.product_variant_id', 'v.id'))->exists();
            } else {
                $stock = $stock || DB::table('product_variants as v')->join('products as p', 'p.id', '=', 'v.product_id')
                    ->whereIn('p.location_id', $ids)->where('v.stock', '<>', 0)->exists();
            }
            if ($stock) {
                throw ValidationException::withMessages(['warehouse' => 'Cannot delete this warehouse while stock remains in its locations. Move or adjust that stock first.']);
            }
            $transfers = DB::table('stock_transfers')->where('organization_id', $locked->organization_id)
                ->whereIn('status', ['pending', 'prepared', 'in_transit'])
                ->where(fn ($q) => $q->whereIn('from_location_id', $ids)->orWhereIn('to_location_id', $ids))->exists();
            $holds = Schema::hasTable('stock_reservations') && DB::table('stock_reservations')->whereIn('location_id', $ids)
                ->whereNull('released_at')->whereNull('consumed_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists();
            $counts = DB::table('stock_audits')->where('organization_id', $locked->organization_id)->whereIn('status', ['draft', 'in_progress'])
                ->where(function ($query) use ($ids, $locked): void {
                    $query->whereIn('warehouse_location_id', $ids);
                    if (Schema::hasColumn('stock_audits', 'warehouse_id')) {
                        $query->orWhere('warehouse_id', $locked->id);
                    }
                })->exists();
            if ($transfers || $holds || $counts) {
                throw ValidationException::withMessages(['warehouse' => 'Finish or cancel active transfers and stock counts, and release stock reservations before deleting this warehouse.']);
            }
            $this->guardLastAssignments($actor, $locked);
            // Keep locations and their history usable; deleting an empty warehouse
            // must not require deleting zero-stock products or historical bin rows.
            foreach ($locations as $location) {
                $location->update(['warehouse_id' => null]);
            }
            $locked->users()->detach();
            $locked->delete();
        });
    }

    /**
     * Deleting a warehouse drops every assignment to it, and a user with no
     * assignment is unrestricted unless the organization restricts
     * unassigned users. A restricted actor may never delete their own last
     * warehouse, and only admins or holders of access_all_warehouses may
     * drop a colleague's last one. Same rule as editing a warehouse's users.
     *
     * @throws ValidationException
     */
    private function guardLastAssignments(User $actor, Warehouse $warehouse): void
    {
        $assigned = $warehouse->users()->pluck('users.id')->map(fn ($id) => (int) $id)->values();
        if ($assigned->isEmpty()) {
            return;
        }

        $access = app(WarehouseAccessService::class);
        $losesLastAssignment = function (User $user) use ($access, $warehouse): bool {
            $ids = $access->accessibleWarehouseIds($user);

            return $ids !== null && array_values(array_diff($ids, [(int) $warehouse->id])) === [];
        };

        if ($assigned->contains((int) $actor->id) && $losesLastAssignment($actor)) {
            $this->refuse(__('You cannot delete your own last assigned warehouse.'));
        }

        if ($actor->isAdmin() || $actor->hasPermission(Permission::ACCESS_ALL_WAREHOUSES)) {
            return;
        }

        // With the organization setting on, an unassigned user sees no
        // warehouse, so the deletion narrows access instead of widening it.
        if ($access->organizationRestrictsToAssigned((int) $warehouse->organization_id)) {
            return;
        }

        // Evaluate each user as a member of this warehouse's organization:
        // their stored organization_id may be a different home organization.
        $organization = app(ActiveOrganization::class);
        $widened = User::query()
            ->whereIn('id', $assigned->all())
            ->get()
            ->map(fn (User $user) => $organization->userIn($user, (int) $warehouse->organization_id))
            ->filter(fn (?User $user) => $user !== null && $losesLastAssignment($user));

        if ($widened->isNotEmpty()) {
            $this->refuse(__('Deleting this warehouse would leave :names with no warehouse assignment, which would give them access to every warehouse. Assign them to another warehouse first, or ask an administrator.', [
                'names' => $widened->pluck('name')->implode(', '),
            ]));
        }
    }

    /**
     * The "assignments" key lets the REST API report a distinct error code.
     *
     * @throws ValidationException
     */
    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['warehouse' => $message, 'assignments' => $message]);
    }
}
