<?php

use App\Support\ApprovalSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Before order approval became a setting, every order was created "pending"
 * approval whether or not anyone meant to approve it. Order approval is off
 * unless an organization turns it on, so an order still pending in an
 * organization that does not require approval was never really waiting for
 * anything: mark it not_required. Approved and rejected orders keep their
 * decision, and organizations that require approval keep their queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('organizations')->select(['id', 'settings'])->orderBy('id')->each(function ($organization) {
            $settings = is_string($organization->settings)
                ? (json_decode($organization->settings, true) ?: [])
                : (array) ($organization->settings ?? []);

            if (ApprovalSettings::fromArray((array) ($settings[ApprovalSettings::KEY] ?? []))->ordersEnabled) {
                return;
            }

            DB::table('orders')
                ->where('organization_id', $organization->id)
                ->where('approval_status', 'pending')
                ->update(['approval_status' => 'not_required']);
        });
    }

    public function down(): void
    {
        // Irreversible in meaning (not_required orders were never decided);
        // the column migration's down() folds them into a valid value.
    }
};
