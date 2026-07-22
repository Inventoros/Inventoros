<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Webhook\StoreWebhookRequest;
use App\Http\Requests\Webhook\UpdateWebhookRequest;
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
     */
    public function index(): Response
    {
        if (! auth()->user()->hasPermission('manage_organization')) {
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
     * @param  Request  $request
     */
    public function store(StoreWebhookRequest $request): RedirectResponse
    {
        if (! auth()->user()->hasPermission('manage_organization')) {
            abort(403);
        }

        $validated = $request->validated();

        $validated['organization_id'] = auth()->user()->organization_id;
        $validated['created_by'] = auth()->id();

        $webhook = Webhook::create($validated);

        // Hand the plaintext signing secret back exactly once so the receiver
        // can be configured. It is $hidden from serialization everywhere else.
        return redirect()->route('webhooks.index')
            ->with('success', 'Webhook created successfully')
            ->with('newWebhookSecret', $webhook->secret);
    }

    /**
     * Display the specified webhook with delivery logs.
     */
    public function show(Webhook $webhook): Response
    {
        if (! auth()->user()->hasPermission('manage_organization') ||
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
     * @param  Request  $request
     */
    public function update(UpdateWebhookRequest $request, Webhook $webhook): RedirectResponse
    {
        if (! auth()->user()->hasPermission('manage_organization') ||
            $webhook->organization_id !== auth()->user()->organization_id) {
            abort(403);
        }

        $validated = $request->validated();

        $webhook->update($validated);

        return redirect()->route('webhooks.show', $webhook)->with('success', 'Webhook updated successfully');
    }

    /**
     * Remove the specified webhook.
     */
    public function destroy(Webhook $webhook): RedirectResponse
    {
        if (! auth()->user()->hasPermission('manage_organization') ||
            $webhook->organization_id !== auth()->user()->organization_id) {
            abort(403);
        }

        $webhook->delete();

        return redirect()->route('webhooks.index')->with('success', 'Webhook deleted successfully');
    }

    /**
     * Regenerate the webhook secret.
     */
    public function regenerateSecret(Webhook $webhook): RedirectResponse
    {
        if (! auth()->user()->hasPermission('manage_organization') ||
            $webhook->organization_id !== auth()->user()->organization_id) {
            abort(403);
        }

        $secret = Str::random(64);
        $webhook->update(['secret' => $secret]);

        // Reveal the rotated secret exactly once so the receiver can be updated.
        return redirect()->route('webhooks.show', $webhook)
            ->with('success', 'Secret regenerated')
            ->with('newWebhookSecret', $secret);
    }

    /**
     * Send a test webhook delivery.
     */
    public function test(Webhook $webhook): RedirectResponse
    {
        if (! auth()->user()->hasPermission('manage_organization') ||
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
     */
    public function retryDelivery(WebhookDelivery $delivery): RedirectResponse
    {
        $webhook = $delivery->webhook;

        if (! auth()->user()->hasPermission('manage_organization') ||
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
