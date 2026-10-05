<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Organization memberships: a user may belong to several organizations, with
 * a base role (admin, manager, member) in each. `users.organization_id` stays
 * the user's home organization (the one that owns the account) and
 * `users.role` the role there; every existing user gets a membership row for
 * their home organization, so single-organization installs behave exactly as
 * before. See docs/plans/2026-10-05-multi-company-and-3pl-design.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 50)->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'user_id']);
            $table->index('user_id');
        });

        $this->backfill();
    }

    /**
     * Give every user with an organization their home membership. Safe to
     * run again: existing memberships are left as they are.
     */
    public function backfill(): void
    {
        $now = now();

        DB::table('users')
            ->whereNotNull('organization_id')
            ->whereIn('organization_id', DB::table('organizations')->select('id'))
            ->select(['id', 'organization_id', 'role'])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('organization_user')
                ->whereColumn('organization_user.user_id', 'users.id')
                ->whereColumn('organization_user.organization_id', 'users.organization_id'))
            // By id, not offset: the rows inserted below change what the
            // whereNotExists() matches.
            ->chunkById(500, function ($users) use ($now): void {
                DB::table('organization_user')->insert($users->map(fn ($user) => [
                    'organization_id' => $user->organization_id,
                    'user_id' => $user->id,
                    'role' => $user->role,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_user');
    }
};
