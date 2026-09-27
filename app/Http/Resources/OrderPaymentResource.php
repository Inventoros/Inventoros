<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Order\OrderPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrderPayment
 */
class OrderPaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'type' => $this->type,
            'amount' => $this->amount,
            'method' => $this->method,
            'method_label' => $this->method?->label(),
            'reference' => $this->reference,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'notes' => $this->notes,
            'recorded_by' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ] : null),
            'voided_at' => $this->voided_at?->toIso8601String(),
            'void_reason' => $this->void_reason,
            'voided_by' => $this->whenLoaded('voider', fn () => $this->voider ? [
                'id' => $this->voider->id,
                'name' => $this->voider->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
