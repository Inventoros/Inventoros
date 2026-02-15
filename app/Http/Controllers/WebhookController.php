<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\WebhookDeliveryJob;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\WebhookService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing webhook configurations.
 *
 * Handles CRUD operations for webhooks, including:
 * - Listing webhooks for an organization
 * - Creating, updating, and deleting webhooks
 * - Regenerating webhook secrets
 * - Sending test webhook deliveries
 * - Retrying failed webhook deliveries
 */
class WebhookController extends Controller
{
    /**
     * Display a listing of webhooks for the organization.
     *
     * @return Response
     */
    public function index(): Response
    {
        if (!auth()->user()->can('manage_organization')) {
            abort(403);
        }

        $webhooks = Webhook::forOrganization(auth()->user()->organization_id)
            ->with('creator')
            ->withCount('deliveries')
            ->latest()
            ->get();

        return Inertia::render('Settings/Webhooks/Index', [
            'webhooks' => $webhooks,
            'availableEvents' => WebhookService::availableEvents(),
            'eventGroups' => WebhookService::eventGroups(),
        ]);
    }

    /**
     * Store a newly created webhook.
     *
     * @param Request $request
     * @return RedirectResponse
     */
    public function store(Request $request): RedirectResponse
    {
        if (!auth()->user()->can('manage_organization')) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'url' => 'required|url|max:2048',
            'events' => 'required|array|min:1',
            'events.*' => 'string|in:' . implode(',', WebhookService::availableEvents()),
            'is_active' => 'boolean',
        ]);

        $validated['organization_id'] = auth()->user()->organization_id;
        $validated['created_by'] = auth()->id();

        Webhook::create($validated);

        return redirect()->route('webhooks.index')->with('success', 'Webhook created successfully');
    }

    /**
     * Display the specified webhook with delivery logs.
     *
     * @param Webhook $webhook
     * @return Response
     */
    public function show(Webhook $webhook): Response
    {
        if (!auth()->user()->can('manage_organization') ||
            $webhook->organization_id !== auth()->user()->organization_id) {
            abort(403);
        }

        $webhook->load('creator');
        $deliveries = $webhook->deliveries()
            ->latest()
            ->paginate(20);

        return Inertia::render('Settings/Webhooks/Show', [
            'webhook' => $webhook,
            'deliveries' => $deliveries,
            'availableEvents' => WebhookService::availableEvents(),
            'eventGroups' => WebhookService::eventGroups(),
        ]);
    }

    /**
     * Update the specified webhook.
     *
     * @param Request $request
     * @param Webhook $webhook
     * @return RedirectResponse
     */
    public function update(Request $request, Webhook $webhook): RedirectResponse
    {
        if (!auth()->user()->can('manage_organization') ||
            $webhook->organization_id !== auth()->user()->organization_id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'url' => 'required|url|max:2048',
            'events' => 'required|array|min:1',
            'events.*' => 'string|in:' . implode(',', WebhookService::availableEvents()),
            'is_active' => 'boolean',
        ]);

        $webhook->update($validated);

        return redirect()->route('webhooks.show', $webhook)->with('success', 'Webhook updated successfully');
    }

    /**
     * Remove the specified webhook.
     *
     * @param Webhook $webhook
     * @return RedirectResponse
     */
    public function destroy(Webhook $webhook): RedirectResponse
    {
        if (!auth()->user()->can('manage_organization') ||
            $webhook->organization_id !== auth()->user()->organization_id) {
            abort(403);
        }

        $webhook->delete();

        return redirect()->route('webhooks.index')->with('success', 'Webhook deleted successfully');
    }

    /**
     * Regenerate the webhook secret.
     *
     * @param Webhook $webhook
     * @return RedirectResponse
     */
    public function regenerateSecret(Webhook $webhook): RedirectResponse
    {
        if (!auth()->user()->can('manage_organization') ||
            $webhook->organization_id !== auth()->user()->organization_id) {
            abort(403);
        }

        $webhook->update(['secret' => Str::random(64)]);

        return redirect()->route('webhooks.show', $webhook)->with('success', 'Secret regenerated');
    }

    /**
     * Send a test webhook delivery.
     *
     * @param Webhook $webhook
     * @return RedirectResponse
     */
    public function test(Webhook $webhook): RedirectResponse
    {
        if (!auth()->user()->can('manage_organization') ||
            $webhook->organization_id !== auth()->user()->organization_id) {
            abort(403);
        }

        $testEvent = $webhook->events[0] ?? 'test';

        WebhookService::dispatch($testEvent, [
            'test' => true,
            'message' => 'This is a test webhook delivery',
            'timestamp' => now()->toIso8601String(),
        ], $webhook->organization_id);

        return redirect()->route('webhooks.show', $webhook)->with('success', 'Test webhook dispatched');
    }

    /**
     * Retry a failed webhook delivery.
     *
     * @param WebhookDelivery $delivery
     * @return RedirectResponse
     */
    public function retryDelivery(WebhookDelivery $delivery): RedirectResponse
    {
        $webhook = $delivery->webhook;

        if (!auth()->user()->can('manage_organization') ||
            $webhook->organization_id !== auth()->user()->organization_id) {
            abort(403);
        }

        $delivery->update([
            'status' => 'pending',
            'attempts' => 0,
        ]);

        WebhookDeliveryJob::dispatch($delivery);

        return redirect()->back()->with('success', 'Delivery retry queued');
    }
}
