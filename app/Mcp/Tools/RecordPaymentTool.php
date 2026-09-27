<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\PaymentMethod;
use App\Mcp\Concerns\AuthenticatesMcpRequest;
use App\Models\Order\Order;
use App\Services\OrderPaymentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class RecordPaymentTool extends Tool
{
    use AuthenticatesMcpRequest;

    protected string $name = 'record_payment';

    protected string $description = 'Record a payment received against a sales order. Partial payments are fine. A payment larger than the balance due is rejected unless allow_overpayment is true. Payments cannot be recorded against cancelled orders. Confirm the order, amount and method with the user first.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'order_id' => $schema->integer()->required()->description('Order id.'),
            'amount' => $schema->number()->required()->description('Amount received, greater than zero.'),
            'method' => $schema->string()->enum(PaymentMethod::values())->required()->description('How it was paid.'),
            'reference' => $schema->string()->description('Transaction id, cheque number, and so on.'),
            'paid_at' => $schema->string()->description('ISO date the money was received, default now.'),
            'notes' => $schema->string()->description('Notes.'),
            'allow_overpayment' => $schema->boolean()->description('Record it even if it exceeds the balance due (the order becomes overpaid). Default false.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize(['record_payments']);

        $validated = $request->validate([
            'order_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'method' => ['required', Rule::in(PaymentMethod::values())],
            'reference' => ['nullable', 'string', 'max:255'],
            'paid_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'allow_overpayment' => ['nullable', 'boolean'],
        ]);

        $order = Order::query()
            ->forOrganization($this->organizationId())
            ->find((int) $validated['order_id']);

        if (! $order) {
            return Response::error('Order not found in this organization.');
        }

        try {
            $payment = app(OrderPaymentService::class)->record($order, $this->user(), [
                'amount' => $validated['amount'],
                'method' => $validated['method'],
                'reference' => $validated['reference'] ?? null,
                'paid_at' => $validated['paid_at'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'allow_overpayment' => (bool) ($validated['allow_overpayment'] ?? false),
            ]);
        } catch (ValidationException $e) {
            return Response::error(collect($e->errors())->flatten()->first() ?? $e->getMessage());
        }

        $order->refresh();

        return Response::json([
            'message' => 'Payment recorded.',
            'payment' => [
                'id' => $payment->id,
                'amount' => $payment->amount,
                'method' => $payment->method->value,
                'reference' => $payment->reference,
                'paid_at' => $payment->paid_at?->toIso8601String(),
            ],
            'order' => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'total' => $order->total,
                'amount_paid' => $order->amount_paid,
                'balance_due' => $order->balanceDue(),
                'payment_status' => $order->payment_status->value,
            ],
        ]);
    }
}
