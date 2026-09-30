<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * orders.approval_status was an ENUM of pending / approved / rejected with a
 * default of pending, so every order started out "waiting for approval" even
 * though nothing required it. It becomes a plain string that also allows
 * not_required, which is now the default: an order only waits for approval
 * when its organization turns order approval on (see ApprovalSettings).
 */
return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL implements Laravel's enum() as a CHECK constraint, which
        // survives a column type change and would reject the new value.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_approval_status_check');
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->string('approval_status', 20)->default('not_required')->change();
        });
    }

    public function down(): void
    {
        DB::table('orders')->where('approval_status', 'not_required')->update(['approval_status' => 'approved']);

        // Blueprint cannot change a column to an enum on PostgreSQL; restore
        // the CHECK constraint Laravel's enum() originally created.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE orders ALTER COLUMN approval_status TYPE varchar(255)");
            DB::statement("ALTER TABLE orders ALTER COLUMN approval_status SET DEFAULT 'pending'");
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_approval_status_check CHECK (approval_status::text = ANY (ARRAY['pending'::character varying, 'approved'::character varying, 'rejected'::character varying]::text[]))");

            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->enum('approval_status', ['pending', 'approved', 'rejected'])->default('pending')->change();
        });
    }
};
