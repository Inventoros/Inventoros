<?php

use App\Models\PermissionSet;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Approval workflows added approve_purchase_orders, approve_stock_adjustments
 * and approve_stock_transfers, and an "Approver" permission set template.
 * RoleSeeder and PermissionSet::getDefaultTemplates() only reach fresh
 * installs, so existing copies of the system Administrator and Manager roles
 * get the permissions here, and installs that seeded the default templates
 * get the Approver template. Approvals stay off until an organization turns
 * them on. Custom roles and sets are left for their owners to decide.
 */
return new class extends Migration
{
    private const APPROVE = ['approve_purchase_orders', 'approve_stock_adjustments', 'approve_stock_transfers'];

    private const ROLES = ['system-administrator', 'system-manager'];

    private const TEMPLATE = 'approver';

    public function up(): void
    {
        foreach (DB::table('roles')->whereIn('slug', self::ROLES)->get(['id', 'permissions']) as $row) {
            $permissions = json_decode((string) $row->permissions, true) ?: [];
            $merged = array_values(array_unique(array_merge($permissions, self::APPROVE)));

            if ($merged !== $permissions) {
                DB::table('roles')->where('id', $row->id)->update(['permissions' => json_encode($merged)]);
            }
        }

        $this->addApproverTemplate();
    }

    public function down(): void
    {
        foreach (DB::table('roles')->whereIn('slug', self::ROLES)->get(['id', 'permissions']) as $row) {
            $permissions = json_decode((string) $row->permissions, true) ?: [];
            $filtered = array_values(array_diff($permissions, self::APPROVE));
            DB::table('roles')->where('id', $row->id)->update(['permissions' => json_encode($filtered)]);
        }

        // Only remove the template while no role uses it.
        DB::table('permission_sets')
            ->where('slug', self::TEMPLATE)
            ->where('is_template', true)
            ->whereNull('organization_id')
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('role_permission_set')
                ->whereColumn('role_permission_set.permission_set_id', 'permission_sets.id'))
            ->delete();
    }

    private function addApproverTemplate(): void
    {
        // Installs that never ran PermissionSetSeeder have no templates at all;
        // leave them that way rather than seed a single stray template.
        $hasTemplates = DB::table('permission_sets')->where('is_template', true)->whereNull('organization_id')->exists();

        if (! $hasTemplates || DB::table('permission_sets')->where('slug', self::TEMPLATE)->exists()) {
            return;
        }

        $template = collect(PermissionSet::getDefaultTemplates())->firstWhere('slug', self::TEMPLATE);

        if ($template === null) {
            return;
        }

        $now = now();

        DB::table('permission_sets')->insert([
            'organization_id' => null,
            'name' => $template['name'],
            'slug' => $template['slug'],
            'description' => $template['description'] ?? null,
            'permissions' => json_encode($template['permissions']),
            'category' => $template['category'] ?? null,
            'icon' => $template['icon'] ?? null,
            'is_template' => true,
            'is_active' => true,
            'position' => (int) DB::table('permission_sets')->where('is_template', true)->max('position') + 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
