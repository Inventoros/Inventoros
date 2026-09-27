<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Webhook\StoreWebhookRequest;
use App\Http\Requests\Webhook\UpdateWebhookRequest;
use App\Http\Resources\WebhookDeliveryResource;
use App\Http\Resources\WebhookResource;
use App\Models\Webhook;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Webhook subscriptions. The signing secret is returned only by `store` and
 * `regenerateSecret`; every other response omits it.
 *
 * @tags Webhooks
 */
class WebhookController extends Controller
{
    use HandlesApiResponses;

    /**
     * List webhooks.
     */
    #[QueryParameter('per_page', description: 'Items per page (default: 15, max: 100)', type: 'integer')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $webhooks = Webhook::forOrganization($request->user()->organization_id)
            ->withCount('deliveries')
            ->latest()
            ->latest('id')
            ->paginate($this->perPage($request));

        return WebhookResource::collection($webhooks);
    }

    /**
     * Create a webhook.
     *
     * The response carries the plaintext signing `secret`. It is shown only
     * here (and on regenerate), so store it now.
     */
    public function store(StoreWebhookRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['organization_id'] = $request->user()->organization_id;
        $validated['created_by'] = $request->user()->id;

        $webhook = Webhook::create($validated);

        return response()->json([
            'message' => 'Webhook created successfully',
            'data' => new WebhookResource($webhook),
            'secret' => $webhook->secret,
        ], 201);
    }

    /**
     * Show a webhook.
     */
    public function show(Request $request, Webhook $webhook): JsonResponse
    {
        $this->ensureOwned($request, $webhook, 'Webhook');

        return response()->json(['data' => new WebhookResource($webhook->loadCount('deliveries'))]);
    }

    /**
     * Update a webhook.
     */
    public function update(UpdateWebhookRequest $request, Webhook $webhook): JsonResponse
    {
        $this->ensureOwned($request, $webhook, 'Webhook');

        $webhook->update($request->validated());

        return response()->json([
            'message' => 'Webhook updated successfully',
            'data' => new WebhookResource($webhook),
        ]);
    }

    /**
     * Delete a webhook.
     */
    public function destroy(Request $request, Webhook $webhook): JsonResponse
    {
        $this->ensureOwned($request, $webhook, 'Webhook');

        $webhook->delete();

        return response()->json(['message' => 'Webhook deleted successfully']);
    }

    /**
     * Regenerate the signing secret.
     *
     * The response carries the new plaintext `secret`, shown only once.
     */
    public function regenerateSecret(Request $request, Webhook $webhook): JsonResponse
    {
        $this->ensureOwned($request, $webhook, 'Webhook');

        $secret = $webhook->rotateSecret();

        return response()->json([
            'message' => 'Secret regenerated',
            'data' => new WebhookResource($webhook),
            'secret' => $secret,
        ]);
    }

    /**
     * List a webhook's deliveries, newest first.
     */
    #[QueryParameter('status', description: 'Filter by delivery status', type: 'string', enum: ['pending', 'success', 'failed'])]
    #[QueryParameter('per_page', description: 'Items per page (default: 15, max: 100)', type: 'integer')]
    public function deliveries(Request $request, Webhook $webhook): AnonymousResourceCollection
    {
        $this->ensureOwned($request, $webhook, 'Webhook');

        $deliveries = $webhook->deliveries()
            ->when($request->input('status'), fn ($query, $status) => $query->where('status', $status))
            ->latest()
            ->latest('id')
            ->paginate($this->perPage($request));

        return WebhookDeliveryResource::collection($deliveries);
    }
}
