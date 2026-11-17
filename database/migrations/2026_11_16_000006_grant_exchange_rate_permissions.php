<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Exchange rates added manage_exchange_rates (Settings > Exchange rates) and
 * override_purchase_order_exchange_rate (enter or confirm a purchase order's
 * rate). RoleSeeder and PermissionSet::getDefaultTemplates() only reach
 * fresh installs, so the existing system Administrator role gets both here
 * (administrators already hold every permission implicitly; this keeps the
 * stored list complete) and the Purchasing Agent template gets the override,
 * as on a fresh install. Custom roles and sets are left for their owners.
 */
return new class extends Migration
{
    private const BOTH = ['manage_exchange_rates', 'override_purchase_order_exchange_rate'];

    private const OVERRIDE = ['override_purchase_order_exchange_rate'];

    public function up(): void
    {
        $this->apply('roles', 'system-administrator', self::BOTH, grant: true);
        $this->apply('permission_sets', 'purchasing-agent', self::OVERRIDE, grant: true);
    }

    public function down(): void
    {
        $this->apply('roles', 'system-administrator', self::BOTH, grant: false);
        $this->apply('permission_sets', 'purchasing-agent', self::OVERRIDE, grant: false);
    }

    /**
     * @param  array<int, string>  $names
     */
    private function apply(string $table, string $slug, array $names, bool $grant): void
    {
        foreach (DB::table($table)->where('slug', $slug)->get(['id', 'permissions']) as $row) {
            $permissions = json_decode((string) $row->permissions, true) ?: [];

            $updated = $grant
                ? array_values(array_unique(array_merge($permissions, $names)))
                : array_values(array_diff($permissions, $names));

            if ($updated !== $permissions) {
                DB::table($table)->where('id', $row->id)->update(['permissions' => json_encode($updated)]);
            }
        }
    }
};
