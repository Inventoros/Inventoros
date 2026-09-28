<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Exceptions\DocumentEmailException;
use App\Mcp\Concerns\AuthenticatesMcpRequest;
use App\Models\Order\Order;
use App\Services\Documents\DocumentRecipients;
use App\Services\OrderInvoiceEmailService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class EmailOrderInvoiceTool extends Tool
{
    use AuthenticatesMcpRequest;

    protected string $name = 'email_order_invoice';

    protected string $description = 'Email an order\'s invoice to the customer with the invoice PDF attached. Assigns the invoice number on first use. Can be re-sent. Fails when the order has no customer email address and no "to" is given.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Order id.'),
            'to' => $schema->string()->description('Recipient override. Defaults to the order\'s customer email.'),
            'cc' => $schema->array()->items($schema->string())->description('Up to '.DocumentRecipients::MAX_CC.' CC email addresses.'),
            'message' => $schema->string()->description('Optional note included in the email body.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize(['edit_orders']);

        $request->validate([
            'id' => ['required', 'integer'],
            'to' => ['nullable', 'string', 'email', 'max:255'],
            'cc' => ['nullable', 'array', 'max:'.DocumentRecipients::MAX_CC],
            'cc.*' => ['string', 'email', 'max:255'],
            'message' => ['nullable', 'string', 'max:'.DocumentRecipients::MAX_MESSAGE_LENGTH],
        ]);

        $order = Order::query()
            ->forOrganization($this->organizationId())
            ->find((int) $request->get('id'));

        if (! $order) {
            return Response::error('Order not found in this organization.');
        }

        try {
            $order = app(OrderInvoiceEmailService::class)->send(
                $order,
                $this->user(),
                $request->get('to'),
                (array) ($request->get('cc') ?? []),
                $request->get('message'),
            );
        } catch (DocumentEmailException $e) {
            return Response::error($e->getMessage());
        }

        return Response::json([
            'message' => "Invoice {$order->invoice_number} queued for {$order->invoice_sent_to}.",
            'id' => $order->id,
            'order_number' => $order->order_number,
            'invoice_number' => $order->invoice_number,
            'invoice_sent_to' => $order->invoice_sent_to,
            'invoice_queued_at' => $order->invoice_queued_at?->toIso8601String(),
            'invoice_sent_at' => $order->invoice_sent_at?->toIso8601String(),
        ]);
    }
}
