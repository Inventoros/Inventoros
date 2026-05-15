<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\AuthenticatesMcpRequest;
use App\Models\Purchasing\PurchaseOrder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsDestructive]
#[IsIdempotent]
class SendPurchaseOrderTool extends Tool
{
    use AuthenticatesMcpRequest;

    protected string $description = 'Mark a draft purchase order as sent to the supplier. Idempotent: a PO already in "sent" returns an error rather than re-sending.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Purchase order id.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize(['edit_purchase_orders', 'manage_purchase_orders']);

        $request->validate(['id' => ['required', 'integer']]);

        $po = PurchaseOrder::query()
            ->forOrganization($this->organizationId())
            ->find((int) $request->get('id'));

        if (! $po) {
            return Response::error('Purchase order not found in this organization.');
        }

        if (! $po->canBeSent()) {
            return Response::error("Purchase order in status [{$po->status}] cannot be sent. Only drafts with at least one item can be sent.");
        }

        $po->markAsSent();

        return Response::json([
            'message' => 'Purchase order sent.',
            'id' => $po->id,
            'po_number' => $po->po_number,
            'status' => $po->status,
        ]);
    }
}
