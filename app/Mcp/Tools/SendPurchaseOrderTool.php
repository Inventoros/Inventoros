<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Exceptions\DocumentEmailException;
use App\Mcp\Concerns\AuthenticatesMcpRequest;
use App\Models\Purchasing\PurchaseOrder;
use App\Services\Documents\DocumentRecipients;
use App\Services\PurchaseOrderEmailService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class SendPurchaseOrderTool extends Tool
{
    use AuthenticatesMcpRequest;

    protected string $name = 'send_purchase_order';

    protected string $description = 'Email a purchase order to its supplier with the PO PDF attached, and mark a draft as sent. A PO that is already sent (or partly received) can be re-sent; its status is unchanged. Fails, leaving the status unchanged, when the supplier has no email address and no "to" is given.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Purchase order id.'),
            'to' => $schema->string()->description('Recipient override. Defaults to the supplier email.'),
            'cc' => $schema->array()->items($schema->string())->description('Up to '.DocumentRecipients::MAX_CC.' CC email addresses.'),
            'message' => $schema->string()->description('Optional note included in the email body.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize(['edit_purchase_orders']);

        $request->validate([
            'id' => ['required', 'integer'],
            'to' => ['nullable', 'string', 'email', 'max:255'],
            'cc' => ['nullable', 'array', 'max:'.DocumentRecipients::MAX_CC],
            'cc.*' => ['string', 'email', 'max:255'],
            'message' => ['nullable', 'string', 'max:'.DocumentRecipients::MAX_MESSAGE_LENGTH],
        ]);

        $po = PurchaseOrder::query()
            ->forOrganization($this->organizationId())
            ->find((int) $request->get('id'));

        if (! $po) {
            return Response::error('Purchase order not found in this organization.');
        }

        try {
            $po = app(PurchaseOrderEmailService::class)->send(
                $po,
                $this->user(),
                $request->get('to'),
                (array) ($request->get('cc') ?? []),
                $request->get('message'),
            );
        } catch (DocumentEmailException $e) {
            return Response::error($e->getMessage());
        }

        return Response::json([
            'message' => "Purchase order emailed to {$po->sent_to}.",
            'id' => $po->id,
            'po_number' => $po->po_number,
            'status' => $po->status,
            'sent_to' => $po->sent_to,
            'sent_at' => $po->sent_at?->toIso8601String(),
        ]);
    }
}
