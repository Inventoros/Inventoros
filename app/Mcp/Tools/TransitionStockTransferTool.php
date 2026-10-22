<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Exceptions\BusinessRuleException;
use App\Mcp\Concerns\AuthenticatesMcpRequest;
use App\Models\Inventory\StockTransfer;
use App\Services\StockTransferService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class TransitionStockTransferTool extends Tool
{
    use AuthenticatesMcpRequest;

    protected string $name = 'transition_stock_transfer';

    protected string $description = 'Prepare (reserve source stock), ship (remove source stock), complete (receive into destination), or cancel an unshipped warehouse transfer. Requires transfer_stock and access to the warehouse performing the action.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Stock transfer id.'),
            'action' => $schema->string()->enum(['prepare', 'ship', 'complete', 'cancel'])->required(),
            'tracking_number' => $schema->string()->description('Tracking number when shipping.'),
            'shipping_method' => $schema->string()->description('Shipping service when shipping.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize(['transfer_stock']);
        $data = $request->validate([
            'id' => ['required', 'integer'], 'action' => ['required', 'in:prepare,ship,complete,cancel'],
            'tracking_number' => ['nullable', 'string', 'max:255'], 'shipping_method' => ['nullable', 'string', 'max:255'],
        ]);
        $transfer = StockTransfer::forOrganization($this->organizationId())->find($data['id']);
        if ($transfer === null) {
            return Response::error('Stock transfer not found in this organization.');
        }
        $service = app(StockTransferService::class);
        try {
            $result = $data['action'] === 'ship'
                ? $service->ship($transfer, $this->user(), array_intersect_key($data, array_flip(['tracking_number', 'shipping_method'])))
                : $service->{$data['action']}($transfer, $this->user());
        } catch (BusinessRuleException $error) {
            return Response::error($error->getMessage());
        }
        return Response::json(['id' => $result->id, 'transfer_number' => $result->transfer_number, 'status' => $result->status]);
    }
}
