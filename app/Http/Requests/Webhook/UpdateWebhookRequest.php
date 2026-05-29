<?php

declare(strict_types=1);

namespace App\Http\Requests\Webhook;

use App\Services\WebhookService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates updating a webhook (web). Rules unchanged from
 * WebhookController::update, including the SSRF guard.
 */
final class UpdateWebhookRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'url' => ['required', 'url', 'max:2048', function ($attribute, $value, $fail) {
                $host = parse_url($value, PHP_URL_HOST);
                if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '::1', '169.254.169.254'])) {
                    $fail('Webhook URL must not point to a local or metadata address.');
                }
                if ($host && filter_var($host, FILTER_VALIDATE_IP)) {
                    if (! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        $fail('Webhook URL must not point to a private or reserved IP address.');
                    }
                }
            }],
            'events' => 'required|array|min:1',
            'events.*' => 'string|in:'.implode(',', WebhookService::availableEvents()),
            'is_active' => 'boolean',
        ];
    }
}
