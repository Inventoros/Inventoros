<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->unsignedBigInteger('organization_id')->nullable()->after('webhook_id');
            $table->index('organization_id');
        });

        // Backfill organization_id from the parent webhook
        DB::statement('
            UPDATE webhook_deliveries
            SET organization_id = (
                SELECT organization_id FROM webhooks WHERE webhooks.id = webhook_deliveries.webhook_id
            )
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->dropIndex(['organization_id']);
            $table->dropColumn('organization_id');
        });
    }
};
