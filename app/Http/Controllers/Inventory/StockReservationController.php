<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Inventory\Product;
use App\Models\Inventory\StockReservation;
use App\Services\StockReservationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The admin list of stock reservations (holds placed by plugins and core):
 * who holds what, under which reference, until when. A user who may manage
 * stock can release a hold, for example one a plugin left behind.
 */
class StockReservationController extends Controller
{
    private const STATUSES = ['active', 'expired', 'consumed', 'released', 'all'];

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', self::STATUSES)],
            'owner' => ['nullable', 'string', 'max:100'],
            'product_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:191'],
        ]);

        $status = $validated['status'] ?? 'active';

        $reservations = StockReservation::query()
            ->with([
                'product:id,name,sku',
                'variant:id,title,sku',
                'location:id,name,code',
                'serial:id,serial_number',
                'batch:id,batch_number',
            ])
            ->tap(fn (Builder $query) => $this->filterStatus($query, $status))
            ->when($validated['owner'] ?? null, fn (Builder $q, string $owner) => $q->where('owner_type', $owner))
            ->when($validated['product_id'] ?? null, fn (Builder $q, int $productId) => $q->where('product_id', $productId))
            ->when($validated['search'] ?? null, fn (Builder $q, string $search) => $q->where('owner_reference', 'like', "%{$search}%"))
            ->orderByRaw('expires_at is null')
            ->orderBy('expires_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (StockReservation $reservation) => [
                'id' => $reservation->id,
                'product' => $reservation->product?->only(['id', 'name', 'sku']),
                'variant' => $reservation->variant?->only(['id', 'title', 'sku']),
                'location' => $reservation->location?->only(['id', 'name', 'code']),
                'serial_number' => $reservation->serial?->serial_number,
                'batch_number' => $reservation->batch?->batch_number,
                'quantity' => $reservation->quantity,
                'owner_type' => $reservation->owner_type,
                'owner_reference' => $reservation->owner_reference,
                'status' => $reservation->status(),
                'expires_at' => $reservation->expires_at?->toIso8601String(),
                'consumed_at' => $reservation->consumed_at?->toIso8601String(),
                'released_at' => $reservation->released_at?->toIso8601String(),
                'release_reason' => $reservation->release_reason,
                'created_at' => $reservation->created_at?->toIso8601String(),
            ]);

        $product = isset($validated['product_id'])
            ? Product::query()->whereKey($validated['product_id'])->first(['id', 'name', 'sku'])
            : null;

        return Inertia::render('Reservations/Index', [
            'reservations' => $reservations,
            'filters' => [
                'status' => $status,
                'owner' => $validated['owner'] ?? null,
                'product_id' => $validated['product_id'] ?? null,
                'search' => $validated['search'] ?? null,
            ],
            'product' => $product?->only(['id', 'name', 'sku']),
            'owners' => StockReservation::query()->distinct()->orderBy('owner_type')->pluck('owner_type')->values(),
            'canRelease' => $request->user()->hasPermission(Permission::MANAGE_STOCK),
        ]);
    }

    /**
     * Release one active hold. The owner hears about it through
     * stock_reservation_released (release_reason "admin").
     */
    public function release(Request $request, StockReservation $reservation, StockReservationService $reservations): RedirectResponse
    {
        if (! $reservation->isActive()) {
            return back()->with('error', 'This reservation is no longer active.');
        }

        $released = $reservations->releaseHold($reservation);

        if ($released > 0) {
            ActivityLog::create([
                'organization_id' => $reservation->organization_id,
                'user_id' => $request->user()->id,
                'subject_type' => StockReservation::class,
                'subject_id' => $reservation->id,
                'action' => 'released',
                'description' => "Released reservation {$reservation->owner_type}/{$reservation->owner_reference} of {$released} unit(s)",
                'properties' => ['product_id' => $reservation->product_id, 'quantity' => $released],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        }

        return back()->with('success', 'Reservation released.');
    }

    /**
     * @param  Builder<StockReservation>  $query
     */
    private function filterStatus(Builder $query, string $status): void
    {
        match ($status) {
            'active' => $query->active(),
            'expired' => $query->whereNull('consumed_at')->whereNull('released_at')
                ->whereNotNull('expires_at')->where('expires_at', '<=', now()),
            'consumed' => $query->whereNotNull('consumed_at'),
            'released' => $query->whereNotNull('released_at'),
            default => null,
        };
    }
}
