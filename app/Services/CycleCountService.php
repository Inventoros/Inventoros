<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Inventory\CycleCountSchedule;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Inventory\StockAudit;
use App\Models\Inventory\StockAuditItem;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Services\Organizations\ActiveOrganization;
use App\Services\Organizations\OrganizationMembershipService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns cycle count schedules into draft stock audits.
 *
 * Each run counts the N products in the schedule's scope that were counted
 * least recently (never-counted first), so repeated runs rotate through the
 * whole scope instead of recounting the same shelf.
 */
final class CycleCountService
{
    /**
     * Run every schedule that is due. Returns how many audits were created.
     */
    public function runDue(?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $created = 0;

        CycleCountSchedule::withoutGlobalScope(OrganizationScope::class)
            ->where('is_active', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $now)
            ->orderBy('next_run_at')
            ->each(function (CycleCountSchedule $schedule) use ($now, &$created) {
                if ($this->run($schedule, $now)) {
                    $created++;
                }
            });

        return $created;
    }

    /**
     * Create the draft audit for one run of $schedule.
     *
     * With $advance (the scheduled path) the schedule moves to its next run
     * time whether or not an audit was created. A manual "run now" passes
     * false and leaves the regular schedule alone.
     *
     * Returns null, creating nothing, when the audit from the previous run
     * is still open (so uncounted work does not pile up) or nothing is in
     * scope.
     */
    public function run(CycleCountSchedule $schedule, ?Carbon $now = null, bool $advance = true): ?StockAudit
    {
        $now ??= Carbon::now();

        return DB::transaction(function () use ($schedule, $now, $advance) {
            $schedule = CycleCountSchedule::withoutGlobalScope(OrganizationScope::class)->whereKey($schedule->getKey())->lockForUpdate()->firstOrFail();

            $audit = null;

            if (! $this->previousRunStillOpen($schedule)) {
                $products = $this->selectProducts($schedule);
                $creator = $this->creatorFor($schedule);

                if ($products->isNotEmpty() && $creator) {
                    $audit = $this->createAudit($schedule, $products, $creator, $now);
                }
            }

            $updates = $audit ? ['last_run_at' => $now, 'last_audit_id' => $audit->id] : [];
            if ($advance) {
                $updates['next_run_at'] = $schedule->nextRunAfter($now);
            }
            if ($updates) {
                $schedule->forceFill($updates)->save();
            }

            if ($audit && $schedule->assigned_to) {
                $assignee = User::find($schedule->assigned_to);
                if ($assignee) {
                    DB::afterCommit(fn () => NotificationService::createCycleCountAssignedNotification($audit, $assignee));
                }
            }

            return $audit;
        });
    }

    /**
     * The products the next run should count: active products in scope,
     * least recently counted first (never counted before anything else),
     * then by id for a stable order.
     *
     * @return Collection<int, Product>
     */
    public function selectProducts(CycleCountSchedule $schedule): Collection
    {
        $orgId = $schedule->organization_id;

        $lastCounted = StockAuditItem::query()
            ->join('stock_audits', 'stock_audits.id', '=', 'stock_audit_items.stock_audit_id')
            ->where('stock_audits.organization_id', $orgId)
            ->whereNotNull('stock_audit_items.counted_at')
            ->groupBy('stock_audit_items.product_id')
            ->select('stock_audit_items.product_id', DB::raw('MAX(stock_audit_items.counted_at) as last_counted_at'));

        $query = Product::withoutGlobalScope(OrganizationScope::class)
            ->where('products.organization_id', $orgId)
            ->where('products.is_active', true)
            ->leftJoinSub($lastCounted, 'last_counts', 'last_counts.product_id', '=', 'products.id')
            ->select('products.*', 'last_counts.last_counted_at');

        $this->applyScope($query, $schedule);

        return $query
            ->orderByRaw('CASE WHEN last_counts.last_counted_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('last_counts.last_counted_at')
            ->orderBy('products.id')
            ->limit(max(1, $schedule->products_per_run))
            ->get();
    }

    /**
     * @param  Builder<Product>  $query
     */
    private function applyScope(Builder $query, CycleCountSchedule $schedule): void
    {
        $scopeId = $schedule->scope_id;

        match ($schedule->scope_type) {
            'location' => $this->inLocations($query, [$scopeId]),
            'warehouse' => $this->inLocations(
                $query,
                ProductLocation::withoutGlobalScope(OrganizationScope::class)
                    ->where('organization_id', $schedule->organization_id)
                    ->where('warehouse_id', $scopeId)
                    ->pluck('id')
                    ->all(),
            ),
            'category' => $query->where('products.category_id', $scopeId),
            default => null,
        };
    }

    /**
     * Products whose primary location is one of $locationIds, or that hold
     * stock in a bin there.
     *
     * @param  Builder<Product>  $query
     * @param  array<int, int|null>  $locationIds
     */
    private function inLocations(Builder $query, array $locationIds): void
    {
        $locationIds = array_values(array_filter($locationIds));

        if ($locationIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $q) use ($locationIds) {
            $q->whereIn('products.location_id', $locationIds)
                ->orWhereIn('products.id', ProductLocationStock::withoutGlobalScope(OrganizationScope::class)
                    ->select('product_id')
                    ->whereIn('location_id', $locationIds)
                    ->where('quantity', '>', 0));
        });
    }

    private function previousRunStillOpen(CycleCountSchedule $schedule): bool
    {
        if (! $schedule->last_audit_id) {
            return false;
        }

        return StockAudit::withoutGlobalScope(OrganizationScope::class)
            ->whereKey($schedule->last_audit_id)
            ->whereIn('status', ['draft', 'in_progress'])
            ->exists();
    }

    /**
     * stock_audits.created_by is required. Attribute the audit to whoever
     * set the schedule up, else its assignee, else an admin of the
     * organization.
     */
    private function creatorFor(CycleCountSchedule $schedule): ?User
    {
        $activeOrganization = app(ActiveOrganization::class);

        foreach ([$schedule->created_by, $schedule->assigned_to] as $id) {
            if ($id && ($user = $activeOrganization->userIn($id, (int) $schedule->organization_id))) {
                return $user;
            }
        }

        $fallback = null;
        foreach (app(OrganizationMembershipService::class)->members((int) $schedule->organization_id)
            ->orderBy('id')
            ->cursor() as $user) {
            $member = $activeOrganization->userIn($user, (int) $schedule->organization_id);
            if ($member?->role === 'admin') {
                return $member;
            }
            $fallback ??= $member;
        }

        return $fallback;
    }

    /**
     * @param  Collection<int, Product>  $products
     */
    private function createAudit(CycleCountSchedule $schedule, Collection $products, User $creator, Carbon $now): StockAudit
    {
        $audit = StockAudit::withoutGlobalScope(OrganizationScope::class)->create([
            'organization_id' => $schedule->organization_id,
            'audit_number' => StockAudit::generateAuditNumber($schedule->organization_id),
            'name' => "{$schedule->name} ({$now->toDateString()})",
            'description' => "Scheduled cycle count: {$products->count()} least recently counted product(s).",
            'status' => 'draft',
            'audit_type' => 'cycle',
            'cycle_count_schedule_id' => $schedule->id,
            'assigned_to' => $schedule->assigned_to,
            'warehouse_location_id' => $schedule->scope_type === 'location' ? $schedule->scope_id : null,
            'created_by' => $creator->id,
        ]);

        foreach ($products as $product) {
            StockAuditItem::create([
                'stock_audit_id' => $audit->id,
                'product_id' => $product->id,
                'location_id' => $schedule->scope_type === 'location' ? $schedule->scope_id : $product->location_id,
                'system_quantity' => $schedule->scope_type === 'location'
                    ? app(ProductLocationStockService::class)->onHandAt($product, (int) $schedule->scope_id)
                    : (int) $product->stock,
                'status' => 'pending',
            ]);
        }

        return $audit;
    }
}
