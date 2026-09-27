<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a payment or refund recorded against an order (web and REST).
 * Balance rules (overpayment, refund ceiling, cancelled orders) are enforced
 * by OrderPaymentService under the order's row lock, not here.
 */
final class RecordOrderPaymentRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['sometimes', Rule::in(PaymentType::values())],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'method' => ['required', Rule::in(PaymentMethod::values())],
            'reference' => ['nullable', 'string', 'max:255'],
            'paid_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'allow_overpayment' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function paymentData(): array
    {
        $data = $this->validated();
        $data['allow_overpayment'] = $this->boolean('allow_overpayment');

        return $data;
    }

    public function isRefund(): bool
    {
        return $this->input('type') === PaymentType::REFUND->value;
    }
}
