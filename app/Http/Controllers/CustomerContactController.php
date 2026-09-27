<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerContact;
use App\Services\Portal\PortalInvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Staff management of a customer's portal contacts: invite, resend the
 * invitation, and revoke access. Requires edit_customers (route middleware).
 */
class CustomerContactController extends Controller
{
    public function __construct(private readonly PortalInvitationService $invitations) {}

    public function store(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorizeCustomer($request, $customer);

        if (! $customer->organization->portal_enabled) {
            return back()->with('error', 'Turn on the customer portal in organization settings before inviting contacts.');
        }

        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('customer_contacts', 'email')->where('organization_id', $customer->organization_id),
            ],
        ], [
            'email.unique' => 'This email already has portal access for a customer in your organization.',
        ]);

        $contact = $this->invitations->invite($customer, $validated['name'], $validated['email'], $request->user());

        return back()->with('success', "Invitation sent to {$contact->email}.");
    }

    public function resend(Request $request, Customer $customer, CustomerContact $contact): RedirectResponse
    {
        $this->authorizeContact($request, $customer, $contact);

        if (! $customer->organization->portal_enabled) {
            return back()->with('error', 'Turn on the customer portal in organization settings before inviting contacts.');
        }

        if ($contact->status() === CustomerContact::STATUS_ACTIVE) {
            return back()->with('error', "{$contact->email} has already set a password. They can use Forgot password on the sign-in page.");
        }

        $this->invitations->send($contact, $request->user());

        return back()->with('success', "Invitation sent to {$contact->email}.");
    }

    public function destroy(Request $request, Customer $customer, CustomerContact $contact): RedirectResponse
    {
        $this->authorizeContact($request, $customer, $contact);

        if ($contact->status() !== CustomerContact::STATUS_REVOKED) {
            $this->invitations->revoke($contact, $request->user());
        }

        return back()->with('success', "Portal access revoked for {$contact->email}.");
    }

    private function authorizeCustomer(Request $request, Customer $customer): void
    {
        // Route binding already applies the organization scope; this is the
        // explicit check the rest of the customer controller makes too.
        abort_unless((int) $customer->organization_id === (int) $request->user()->organization_id, 404);
    }

    private function authorizeContact(Request $request, Customer $customer, CustomerContact $contact): void
    {
        $this->authorizeCustomer($request, $customer);

        abort_unless(
            (int) $contact->customer_id === (int) $customer->id
                && (int) $contact->organization_id === (int) $customer->organization_id,
            404,
        );
    }
}
