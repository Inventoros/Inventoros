<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Exceptions\ApprovalException;
use App\Mcp\Concerns\AuthenticatesMcpRequest;
use App\Models\Purchasing\PurchaseOrder;
use App\Services\ApprovalService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class SubmitPurchaseOrderForApprovalTool extends Tool
{
    use AuthenticatesMcpRequest;

    protected string $name = 'submit_purchase_order_for_approval';

    protected string $description = 'Submit a draft purchase order for approval. Only needed when the organization requires approval for this PO (sending it fails with an approval error otherwise). Approvers are notified; the PO cannot be edited or sent until one of them decides.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Purchase order id.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize(['edit_purchase_orders']);

        $request->validate(['id' => ['required', 'integer']]);

        $po = PurchaseOrder::query()
            ->forOrganization($this->organizationId())
            ->find((int) $request->get('id'));

        if (! $po) {
            return Response::error('Purchase order not found in this organization.');
        }

        try {
            $po = app(ApprovalService::class)->submitPurchaseOrder($po, $this->user());
        } catch (ApprovalException $e) {
            return Response::error($e->getMessage());
        }

        return Response::json([
            'message' => "Purchase order {$po->po_number} submitted for approval.",
            'id' => $po->id,
            'po_number' => $po->po_number,
            'approval_status' => $po->approval_status,
        ]);
    }
}
