<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Exceptions\DocumentEmailException;
use App\Mcp\Concerns\AuthenticatesMcpRequest;
use App\Mcp\Support\UntrustedText;
use App\Models\Purchasing\PurchaseOrder;
use App\Services\PurchaseOrderEmailService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class MarkPurchaseOrderSentTool extends Tool
{
    use AuthenticatesMcpRequest;

    protected string $name = 'mark_purchase_order_sent';

    protected string $description = 'Mark a draft purchase order as sent WITHOUT emailing the supplier, for orders already placed another way (the supplier\'s website, a phone call). The PO can then be received. Only drafts with at least one item; a draft that needs approval must be approved first. Use send_purchase_order instead to email the PO to the supplier.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Purchase order id.'),
        ];
    }

    public function handle(Request $request): Response
    {
        UntrustedText::stripArguments($request);

        $this->authorize(['edit_purchase_orders']);

        $request->validate([
            'id' => ['required', 'integer'],
        ]);

        $po = PurchaseOrder::query()
            ->forOrganization($this->organizationId())
            ->find((int) $request->get('id'));

        if (! $po) {
            return Response::error('Purchase order not found in this organization.');
        }

        try {
            $po = app(PurchaseOrderEmailService::class)->markSent($po, $this->user());
        } catch (DocumentEmailException $e) {
            return Response::error($e->getMessage());
        }

        return Response::json([
            'message' => "Purchase order {$po->po_number} marked as sent. No email was sent.",
            'id' => $po->id,
            'po_number' => $po->po_number,
            'status' => $po->status,
        ]);
    }
}
