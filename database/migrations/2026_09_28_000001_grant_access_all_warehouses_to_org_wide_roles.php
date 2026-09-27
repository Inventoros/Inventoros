<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Warehouse assignments now restrict what a user can see. Roles whose job is
 * organization-wide (the system Manager role and the Inventory Manager and
 * Read-Only Auditor permission set templates) get the new
 * access_all_warehouses permission on upgrade, matching the seeders, so
 * existing managers are not narrowed by an assignment made for the switcher.
 */
return new class extends Migration
{
    private const PERMISSION = 'access_all_warehouses';

    public function up(): void
    {
        $this->grant('roles', ['system-manager']);
        $this->grant('permission_sets', ['inventory-manager', 'read-only-auditor']);
    }

    public function down(): void
    {
        $this->revoke('roles', ['system-manager']);
        $this->revoke('permission_sets', ['inventory-manager', 'read-only-auditor']);
    }

    /**
     * @param  array<int, string>  $slugs
     */
    private function grant(string $table, array $slugs): void
    {
        foreach (DB::table($table)->whereIn('slug', $slugs)->get(['id', 'permissions']) as $row) {
            $permissions = json_decode((string) $row->permissions, true) ?: [];

            if (! in_array(self::PERMISSION, $permissions, true)) {
                $permissions[] = self::PERMISSION;
                DB::table($table)->where('id', $row->id)->update(['permissions' => json_encode(array_values($permissions))]);
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
            $filtered = array_values(array_diff($permissions, [self::PERMISSION]));
            DB::table($table)->where('id', $row->id)->update(['permissions' => json_encode($filtered)]);
        }
    }
};
