<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer portal sign-ins. A customer company can have several contacts;
 * each authenticates on the separate `customer` guard and only ever sees its
 * own customer's records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            // Null until the contact accepts their invitation.
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamp('invited_at')->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            // Sign-in is per organization, so an email is unique within one.
            $table->unique(['organization_id', 'email']);
            $table->index(['organization_id', 'customer_id']);
        });

        // Separate broker table so contact reset tokens can never be used
        // against staff accounts (or the reverse). The `email` column holds
        // "{organization_id}|{email}" so the same address at two
        // organizations gets two independent tokens.
        Schema::create('customer_contact_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_contact_password_reset_tokens');
        Schema::dropIfExists('customer_contacts');
    }
};
