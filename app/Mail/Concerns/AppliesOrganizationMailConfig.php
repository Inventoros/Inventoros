<?php

declare(strict_types=1);

namespace App\Mail\Concerns;

use App\Services\SettingsService;
use Illuminate\Support\Facades\Log;

/**
 * Applies the sending organization's stored mail configuration from inside the
 * mailable's build() — which runs in the queue WORKER, the process that
 * actually delivers the message. The org id travels in the mailable's $data
 * payload (serialized with the queued job), so no auth context is needed.
 * A settings failure degrades to the system default mailer rather than failing
 * the job.
 */
trait AppliesOrganizationMailConfig
{
    protected function applyOrganizationMailConfig(): void
    {
        $organizationId = $this->data['organization_id'] ?? null;

        if ($organizationId === null) {
            return;
        }

        try {
            SettingsService::applyEmailConfig((int) $organizationId);
        } catch (\Throwable $e) {
            Log::warning('Could not apply organization mail config; using system default', [
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
