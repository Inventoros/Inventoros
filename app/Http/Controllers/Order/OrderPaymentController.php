<?php

declare(strict_types=1);

namespace App\Http\Controllers\Order;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\RecordOrderPaymentRequest;
use App\Models\Order\Order;
use App\Models\Order\OrderPayment;
use App\Services\OrderPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Payments panel actions on the order page: record a payment or refund, and
 * void one. The balance rules live in OrderPaymentService; its validation
 * errors go back to the page as field errors.
 */
class OrderPaymentController extends Controller
{
    public function __construct(private readonly OrderPaymentService $payments) {}

    public function store(RecordOrderPaymentRequest $request, Order $order): RedirectResponse
    {
        $this->authorizeOrder($request, $order);

        if ($request->isRefund()) {
            $this->payments->refund($order, $request->user(), $request->paymentData());
            $message = 'Refund recorded.';
        } else {
            $this->payments->record($order, $request->user(), $request->paymentData());
            $message = 'Payment recorded.';
        }

        return redirect()->route('orders.show', $order)->with('success', $message);
    }

    public function void(Request $request, Order $order, OrderPayment $payment): RedirectResponse
    {
        $this->authorizeOrder($request, $order);

        if ($payment->order_id !== $order->id) {
            abort(404);
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->payments->void($payment, $request->user(), $validated['reason'] ?? null);

        return redirect()->route('orders.show', $order)
            ->with('success', $payment->isRefund() ? 'Refund voided.' : 'Payment voided.');
    }

    /**
     * Bulk-settle orders placed before payment tracking: every untracked
     * order dated before the given day is marked paid with a reconciling
     * payment (see OrderPaymentService::markPreTrackingOrdersPaid).
     */
    public function markPreTrackingPaid(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'before' => ['required', 'date_format:Y-m-d'],
        ]);

        $count = $this->payments->markPreTrackingOrdersPaid(
            (int) $request->user()->organization_id,
            $request->user(),
            Carbon::createFromFormat('Y-m-d', $validated['before'])->startOfDay(),
        );

        return back()->with('success', trans_choice(
            '{0} No untracked orders were placed before that date.|{1} Marked 1 order as paid.|[2,*] Marked :count orders as paid.',
            $count,
            ['count' => $count],
        ));
    }

    private function authorizeOrder(Request $request, Order $order): void
    {
        if ($order->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }
    }
}
