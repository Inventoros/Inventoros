<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Exceptions\ApprovalException;
use App\Mcp\Concerns\AuthenticatesMcpRequest;
use App\Services\ApprovalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class DecideApprovalTool extends Tool
{
    use AuthenticatesMcpRequest;

    protected string $name = 'decide_approval';

    protected string $description = 'Approve or reject a pending request. WARNING: approving a stock adjustment changes stock; rejecting a transfer cancels it; rejecting a sales order cancels it and restocks its goods. Always confirm with the user first. A reason (notes) is required to reject. You cannot decide on your own request unless you are an admin and the organization allows it.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->required()->enum(ApprovalService::TYPES)->description('What kind of request.'),
            'id' => $schema->integer()->required()->description('Id of the purchase order, stock adjustment request or stock transfer.'),
            'decision' => $schema->string()->required()->enum(['approve', 'reject']),
            'notes' => $schema->string()->description('Comment for the requester. Required when rejecting.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', ApprovalService::TYPES)],
            'id' => ['required', 'integer'],
            'decision' => ['required', 'string', 'in:approve,reject'],
            'notes' => ['nullable', 'string', 'max:1000', 'required_if:decision,reject'],
        ]);

        try {
            $this->authorize([ApprovalService::permissionFor($validated['type'])->value]);
        } catch (AuthorizationException) {
            return Response::error('You do not have permission to approve this kind of request.');
        }

        $approvals = app(ApprovalService::class);

        try {
            $subject = $validated['decision'] === 'approve'
                ? $approvals->approve($this->user(), $validated['type'], (int) $validated['id'], $validated['notes'] ?? null)
                : $approvals->reject($this->user(), $validated['type'], (int) $validated['id'], (string) $validated['notes']);
        } catch (ApprovalException $e) {
            return Response::error($e->getMessage());
        }

        return Response::json([
            'message' => 'Request '.($validated['decision'] === 'approve' ? 'approved' : 'rejected').'.',
            'item' => $approvals->describe($validated['type'], $subject),
        ]);
    }
}
