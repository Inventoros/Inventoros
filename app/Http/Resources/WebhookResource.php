<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Webhook;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Webhook configuration. The signing secret is deliberately absent: it is
 * revealed only once, alongside this resource, on create and regenerate.
 *
 * @mixin Webhook
 */
class WebhookResource extends JsonResource
{
    /**
     * Transform the webhook resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => $this->url,
            'events' => $this->events,
            'is_active' => $this->is_active,
            'created_by' => $this->created_by,
            'deliveries_count' => $this->whenCounted('deliveries'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
