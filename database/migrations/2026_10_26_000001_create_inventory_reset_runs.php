<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No other table references this row: locking it does not conflict
        // with the organization FK checks performed by ordinary stock writes.
        Schema::create('inventory_workflow_locks', function (Blueprint $table): void {
            $table->foreignId('organization_id')->primary()->constrained()->cascadeOnDelete();
        });
        Schema::create('inventory_reset_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->string('signature', 64);
            $table->string('reason', 500);
            $table->json('counts');
            $table->string('error_code')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_reset_runs');
        Schema::dropIfExists('inventory_workflow_locks');
    }
};
