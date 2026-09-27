<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Separate the security audit trail (sign-ins, 2FA, tokens, permission
     * denials, role changes) from ordinary record changes. Existing rows are
     * record changes, so they take the `audit` default.
     */
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->string('category', 32)->default('audit')->after('user_id');
            $table->index(['organization_id', 'category', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'category', 'created_at']);
            $table->dropColumn('category');
        });
    }
};
