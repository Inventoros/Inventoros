<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Custom and system role assignments belong to one organization: a user who
 * holds the Administrator role in one organization must not hold it in
 * another they are a member of. Existing assignments are stamped with the
 * user's home organization, which is the only one they could mean.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('role_user', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
        });

        $this->backfill();

        // The same role (a system role, for instance) may now be held in two
        // organizations. Add the wider index first: on MySQL the role_id
        // foreign key needs an index that starts with role_id at all times.
        Schema::table('role_user', function (Blueprint $table) {
            $table->unique(['role_id', 'user_id', 'organization_id'], 'role_user_role_user_organization_unique');
        });

        Schema::table('role_user', function (Blueprint $table) {
            $table->dropUnique(['role_id', 'user_id']);
            $table->index(['user_id', 'organization_id']);
        });
    }

    /**
     * Stamp unbound assignments with the user's home organization.
     */
    public function backfill(): void
    {
        DB::table('role_user')->whereNull('organization_id')->update([
            'organization_id' => DB::raw('(select users.organization_id from users where users.id = role_user.user_id)'),
        ]);
    }

    public function down(): void
    {
        // Keep one assignment per role and user (the home organization's)
        // before the narrower unique index comes back.
        DB::table('role_user')
            ->whereNotNull('organization_id')
            ->whereRaw('organization_id <> coalesce((select users.organization_id from users where users.id = role_user.user_id), 0)')
            ->delete();

        // MySQL dropped the index it made for the user_id foreign key when
        // the (user_id, organization_id) index arrived; give it one back first.
        Schema::table('role_user', function (Blueprint $table) {
            $table->unique(['role_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::table('role_user', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'organization_id']);
            $table->dropUnique('role_user_role_user_organization_unique');
            $table->dropConstrainedForeignId('organization_id');
        });
    }
};
