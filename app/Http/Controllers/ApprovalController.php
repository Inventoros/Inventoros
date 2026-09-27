<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ApprovalException;
use App\Services\ApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "Pending approvals" page and the approve / reject actions for
 * purchase orders, stock adjustments and stock transfers.
 *
 * Who may decide is enforced by ApprovalService (permission per type plus
 * the self-approval rule), shared with the REST API, MCP and GraphQL.
 */
class ApprovalController extends Controller
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Approvals/Index', [
            'pending' => $this->approvals->pendingFor($user),
            'mine' => $this->approvals->requestedBy($user),
        ]);
    }

    public function approve(Request $request, string $type, int $id): RedirectResponse
    {
        $validated = $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        return $this->decide(fn () => $this->approvals->approve($request->user(), $type, $id, $validated['notes'] ?? null), 'Request approved.');
    }

    public function reject(Request $request, string $type, int $id): RedirectResponse
    {
        $validated = $request->validate([
            'notes' => 'required|string|max:1000',
        ], [
            'notes.required' => 'Give a reason for rejecting this request.',
        ]);

        return $this->decide(fn () => $this->approvals->reject($request->user(), $type, $id, $validated['notes']), 'Request rejected.');
    }

    private function decide(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (ApprovalException $e) {
            // No permission at all, or not in this organization: a hard stop.
            // Self-approval and already-decided are ordinary messages.
            if (in_array($e->reason, [ApprovalException::FORBIDDEN, ApprovalException::NOT_FOUND], true)) {
                abort($e->status(), $e->getMessage());
            }

            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', $success);
    }
}
