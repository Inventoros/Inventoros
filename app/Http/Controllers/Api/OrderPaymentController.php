<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\RecordOrderPaymentRequest;
use App\Http\Resources\OrderPaymentResource;
use App\Models\Order\Order;
use App\Models\Order\OrderPayment;
use App\Services\OrderPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Payments and refunds against an order. Reads need view_payments, writes
 * record_payments. Balance rules are enforced by OrderPaymentService under
 * the order's row lock and surface as 422 validation errors.
 *
 * @tags Order Payments
 */
class OrderPaymentController extends Controller
{
    public function __construct(private readonly OrderPaymentService $payments) {}

    /**
     * List an order's payments and refunds, voided ones included.
     */
    public function index(Request $request, Order $order): AnonymousResourceCollection
    {
        $this->ensureOrganization($request, $order);

        return OrderPaymentResource::collection(
            $order->payments()->with(['user', 'voider'])->orderBy('paid_at')->orderBy('id')->get()
        );
    }

    /**
     * Record a payment. A payment above the balance due is rejected unless
     * `allow_overpayment` is true.
     */
    public function store(RecordOrderPaymentRequest $request, Order $order): JsonResponse
    {
        $this->ensureOrganization($request, $order);

        $payment = $this->payments->record($order, $request->user(), $request->paymentData());

        return $this->created($payment, 'Payment recorded');
    }

    /**
     * Record a refund. A refund cannot exceed what is currently paid.
     */
    public function refund(RecordOrderPaymentRequest $request, Order $order): JsonResponse
    {
        $this->ensureOrganization($request, $order);

        $refund = $this->payments->refund($order, $request->user(), $request->paymentData());

        return $this->created($refund, 'Refund recorded');
    }

    /**
     * Void a payment or refund.
     */
    public function void(Request $request, Order $order, OrderPayment $payment): JsonResponse
    {
        $this->ensureOrganization($request, $order);

        if ($payment->order_id !== $order->id) {
            abort(404, 'Payment not found');
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $voided = $this->payments->void($payment, $request->user(), $validated['reason'] ?? null);

        return response()->json([
            'message' => 'Payment voided',
            'data' => new OrderPaymentResource($voided),
            'order' => $this->orderSummary($order),
        ]);
    }

    private function created(OrderPayment $payment, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => new OrderPaymentResource($payment),
            'order' => $this->orderSummary($payment->order),
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function orderSummary(Order $order): array
    {
        $order = $order->fresh();

        return [
            'id' => $order->id,
            'total' => $order->total,
            'amount_paid' => $order->amount_paid,
            'balance_due' => $order->balanceDue(),
            'payment_status' => $order->payment_status,
        ];
    }

    private function ensureOrganization(Request $request, Order $order): void
    {
        if ($order->organization_id !== $request->user()->organization_id) {
            abort(404, 'Order not found');
        }
    }
}
