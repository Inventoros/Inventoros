<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An API token works in exactly one organization: the one that was active
 * when it was created. Existing tokens are bound to their user's home
 * organization, the only one they could reach before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('tokenable_id')->constrained()->cascadeOnDelete();
        });

        $this->backfill();
    }

    /**
     * Bind unbound user tokens to their user's home organization.
     */
    public function backfill(): void
    {
        DB::table('personal_access_tokens')
            ->whereNull('organization_id')
            ->where('tokenable_type', 'App\Models\User')
            ->update([
                'organization_id' => DB::raw('(select users.organization_id from users where users.id = personal_access_tokens.tokenable_id)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
        });
    }
};
