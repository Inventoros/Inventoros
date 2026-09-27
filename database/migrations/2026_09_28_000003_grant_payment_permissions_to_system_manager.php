<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Existing installs seeded the system Manager role before view_payments and
 * record_payments existed. Give it both, matching RoleSeeder, so managers keep
 * working with the orders they already manage. Administrators hold every
 * permission implicitly; custom roles are left for their owners to decide.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['view_payments', 'record_payments'];

    public function up(): void
    {
        $role = DB::table('roles')->where('slug', 'system-manager')->first();
        if ($role === null) {
            return;
        }

        $permissions = json_decode((string) $role->permissions, true) ?: [];
        $merged = array_values(array_unique(array_merge($permissions, self::PERMISSIONS)));

        DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($merged)]);
    }

    public function down(): void
    {
        $role = DB::table('roles')->where('slug', 'system-manager')->first();
        if ($role === null) {
            return;
        }

        $permissions = json_decode((string) $role->permissions, true) ?: [];
        $remaining = array_values(array_diff($permissions, self::PERMISSIONS));

        DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($remaining)]);
    }
};
