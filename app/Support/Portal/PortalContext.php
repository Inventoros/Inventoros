<?php

declare(strict_types=1);

namespace App\Support\Portal;

use App\Models\Auth\Organization;
use App\Models\CustomerContact;
use Illuminate\Http\Request;

/**
 * Reads the portal organization and signed-in contact that the portal
 * middleware attached to the request.
 */
final class PortalContext
{
    public const ORGANIZATION = 'portal.organization';

    public const CONTACT = 'portal.contact';

    public static function organization(Request $request): Organization
    {
        $organization = $request->attributes->get(self::ORGANIZATION);

        abort_unless($organization instanceof Organization, 404);

        return $organization;
    }

    public static function contact(Request $request): CustomerContact
    {
        $contact = $request->attributes->get(self::CONTACT);

        // Only reachable if a route forgot the portal.auth middleware; fail
        // closed rather than serve anything.
        abort_unless($contact instanceof CustomerContact, 403);

        return $contact;
    }
}
