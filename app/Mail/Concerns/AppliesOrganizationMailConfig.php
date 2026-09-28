<?php

declare(strict_types=1);

namespace App\Mail\Concerns;

use App\Services\OrganizationMailer;

/**
 * Sends the mailable through the sending organization's own mailer. Called
 * from build(), which runs in the queue WORKER (the process that actually
 * delivers the message); the org id travels in the mailable's $data payload,
 * so no auth context is needed.
 *
 * Only the mailable's own mailer is selected: the global mail config is left
 * alone, so one organization's settings can never leak into another's jobs
 * in a long-lived worker. With no usable org transport (or a settings
 * failure) the system mailer is used.
 *
 * Token-bearing mail (password resets, invitations) must NOT use this: it
 * always goes through the system mailer.
 */
trait AppliesOrganizationMailConfig
{
    protected function applyOrganizationMailConfig(): void
    {
        $organizationId = $this->data['organization_id'] ?? null;

        if ($organizationId === null) {
            return;
        }

        // Always assign, even null, so a reused instance never keeps a stale mailer.
        $this->mailer = OrganizationMailer::for((int) $organizationId);
    }
}
