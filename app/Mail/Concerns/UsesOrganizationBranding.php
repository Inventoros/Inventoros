<?php

declare(strict_types=1);

namespace App\Mail\Concerns;

use App\Models\Auth\Organization;

/**
 * Resolves the sending organization's name (and contact email) for the email
 * layout, so every message is branded as the organization rather than the
 * app. Like AppliesOrganizationMailConfig it reads the org id from the
 * mailable's $data payload, so it works in the queue worker with no auth.
 */
trait UsesOrganizationBranding
{
    /**
     * @return array{brandName: string, brandEmail: string|null}
     */
    protected function organizationBranding(): array
    {
        $organizationId = $this->data['organization_id'] ?? null;

        $organization = $organizationId !== null
            ? Organization::query()->withoutGlobalScopes()->find((int) $organizationId)
            : null;

        return [
            'brandName' => $organization?->name ?: (string) config('app.name', 'Inventoros'),
            'brandEmail' => $organization?->email ?: null,
        ];
    }
}
