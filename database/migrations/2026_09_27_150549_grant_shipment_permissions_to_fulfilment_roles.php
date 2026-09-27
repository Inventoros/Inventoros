<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Shipments add view_shipments and create_shipments. Existing copies of the
 * roles that fulfil orders get them on upgrade, matching the seeders: the
 * Administrator and Manager system roles and the Order Processor and
 * Warehouse Staff templates get both, the Read-Only Auditor gets view only.
 */
return new class extends Migration
{
    private const BOTH = ['view_shipments', 'create_shipments'];

    private const VIEW = ['view_shipments'];

    public function up(): void
    {
        $this->grant('roles', ['system-administrator', 'system-manager'], self::BOTH);
        $this->grant('permission_sets', ['order-processor', 'warehouse-staff'], self::BOTH);
        $this->grant('permission_sets', ['read-only-auditor'], self::VIEW);
    }

    public function down(): void
    {
        $this->revoke('roles', ['system-administrator', 'system-manager']);
        $this->revoke('permission_sets', ['order-processor', 'warehouse-staff', 'read-only-auditor']);
    }

    /**
     * @param  array<int, string>  $slugs
     * @param  array<int, string>  $grant
     */
    private function grant(string $table, array $slugs, array $grant): void
    {
        foreach (DB::table($table)->whereIn('slug', $slugs)->get(['id', 'permissions']) as $row) {
            $permissions = json_decode((string) $row->permissions, true) ?: [];
            $merged = array_values(array_unique(array_merge($permissions, $grant)));

            if ($merged !== $permissions) {
                DB::table($table)->where('id', $row->id)->update(['permissions' => json_encode($merged)]);
            }
        }
    }

    /**
     * @param  array<int, string>  $slugs
     */
    private function revoke(string $table, array $slugs): void
    {
        foreach (DB::table($table)->whereIn('slug', $slugs)->get(['id', 'permissions']) as $row) {
            $permissions = json_decode((string) $row->permissions, true) ?: [];
            $filtered = array_values(array_diff($permissions, self::BOTH));
            DB::table($table)->where('id', $row->id)->update(['permissions' => json_encode($filtered)]);
        }
    }
};
