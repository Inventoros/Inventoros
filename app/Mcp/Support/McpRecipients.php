<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use App\Models\Order\Order;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Recipient policy for documents emailed by MCP tools. An agent can be
 * steered by text planted in the records it reads, so through MCP an invoice
 * or purchase order may only go to an address already on file for the
 * order's customer or the purchase order's supplier, or to the
 * organization's own users; CC follows the same rule. Sending anywhere else
 * stays possible from the web UI (and the REST API), where a person chooses
 * the recipient.
 */
final class McpRecipients
{
    /**
     * The order's customer email, its linked customer's email and the
     * customer's portal contacts that have not been revoked.
     *
     * @return array<int, string>
     */
    public static function forOrder(Order $order): array
    {
        $order->loadMissing('customer');
        $customer = $order->customer;

        $addresses = [$order->customer_email, $customer?->email];

        if ($customer !== null && (int) $customer->organization_id === (int) $order->organization_id) {
            $contacts = $customer->contacts()->whereNull('revoked_at')->pluck('email')->all();
            $addresses = array_merge($addresses, $contacts);
        }

        return self::normalize($addresses);
    }

    /**
     * The purchase order's supplier email.
     *
     * @return array<int, string>
     */
    public static function forPurchaseOrder(PurchaseOrder $purchaseOrder): array
    {
        $purchaseOrder->loadMissing('supplier');

        return self::normalize([$purchaseOrder->supplier?->email]);
    }

    /**
     * Why these recipients are refused, or null when they are allowed.
     *
     * @param  array<int, string>  $onFile  addresses from forOrder() / forPurchaseOrder()
     * @param  array<int, mixed>  $cc
     */
    public static function violation(?string $to, array $cc, array $onFile, int $organizationId, string $document): ?string
    {
        $to = mb_strtolower(trim((string) $to));

        if ($to !== '' && ! in_array($to, $onFile, true) && self::staff([$to], $organizationId) === []) {
            return "Through MCP, a {$document} can only be emailed to an address on file ("
                .($onFile === [] ? 'none recorded' : implode(', ', $onFile))
                .') or to a user of this organization. To send it to any other address, use the web UI.';
        }

        $cc = array_values(array_diff(self::normalize($cc), $onFile));

        if ($cc === []) {
            return null;
        }

        $outside = array_values(array_diff($cc, self::staff($cc, $organizationId)));

        if ($outside !== []) {
            return 'Through MCP, CC is limited to addresses on file and users of this organization; not allowed: '
                .implode(', ', $outside).'. To copy anyone else, use the web UI.';
        }

        return null;
    }

    /**
     * Which of these (lower-cased) addresses belong to users of the
     * organization. Staff are the organization's own people, not an
     * exfiltration target.
     *
     * @param  array<int, string>  $addresses
     * @return array<int, string>
     */
    private static function staff(array $addresses, int $organizationId): array
    {
        return User::query()
            ->memberOf($organizationId)
            ->whereIn(DB::raw('LOWER(users.email)'), $addresses)
            ->pluck('users.email')
            ->map(fn ($email) => mb_strtolower(trim((string) $email)))
            ->all();
    }

    /**
     * @param  array<int, mixed>  $addresses
     * @return array<int, string>
     */
    private static function normalize(array $addresses): array
    {
        $clean = [];

        foreach ($addresses as $address) {
            $address = mb_strtolower(trim((string) $address));

            if ($address !== '') {
                $clean[$address] = $address;
            }
        }

        return array_values($clean);
    }
}
