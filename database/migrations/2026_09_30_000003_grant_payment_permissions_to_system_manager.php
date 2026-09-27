<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Existing installs seeded the system Manager role and the permission set
 * templates before view_payments and record_payments existed. Grant them the
 * way RoleSeeder and PermissionSet::getDefaultTemplates() now do, so managers
 * and order processors keep working with the orders they already handle.
 * Administrators hold every permission implicitly; custom roles and sets are
 * left for their owners to decide.
 */
return new class extends Migration
{
    private const BOTH = ['view_payments', 'record_payments'];

    private const VIEW = ['view_payments'];

    public function up(): void
    {
        $this->apply('roles', 'system-manager', self::BOTH, grant: true);
        $this->apply('permission_sets', 'order-processor', self::BOTH, grant: true);
        $this->apply('permission_sets', 'read-only-auditor', self::VIEW, grant: true);
        $this->apply('permission_sets', 'reports-viewer', self::VIEW, grant: true);
    }

    public function down(): void
    {
        $this->apply('roles', 'system-manager', self::BOTH, grant: false);
        $this->apply('permission_sets', 'order-processor', self::BOTH, grant: false);
        $this->apply('permission_sets', 'read-only-auditor', self::VIEW, grant: false);
        $this->apply('permission_sets', 'reports-viewer', self::VIEW, grant: false);
    }

    /**
     * @param  array<int, string>  $names
     */
    private function apply(string $table, string $slug, array $names, bool $grant): void
    {
        foreach (DB::table($table)->where('slug', $slug)->get(['id', 'permissions']) as $row) {
            $permissions = json_decode((string) $row->permissions, true) ?: [];

            $permissions = $grant
                ? array_values(array_unique(array_merge($permissions, $names)))
                : array_values(array_diff($permissions, $names));

            DB::table($table)->where('id', $row->id)->update(['permissions' => json_encode($permissions)]);
        }
    }
};
