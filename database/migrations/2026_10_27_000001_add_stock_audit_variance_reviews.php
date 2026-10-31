<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_audits', fn (Blueprint $table) => $table->boolean('require_variance_approval')->default(false));
        Schema::table('stock_audit_items', function (Blueprint $table): void {
            $table->string('review_status', 24)->default('not_required');
            $table->string('review_revision', 64)->nullable();
            $table->unsignedBigInteger('recount_generation')->default(0);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reviewer_name')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
        });
        Schema::create('stock_audit_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_audit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_audit_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('count_sequence');
            $table->string('count_revision', 64);
            $table->string('decision', 24);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reviewer_name');
            $table->text('notes')->nullable();
            $table->timestamp('created_at');
            $table->unique(['stock_audit_item_id', 'count_sequence'], 'stock_audit_reviews_item_count_unique');
            $table->index(['organization_id', 'stock_audit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_audit_reviews');
        Schema::table('stock_audit_items', function (Blueprint $table): void {
            $table->dropForeign(['reviewed_by']);
            $table->dropColumn(['review_status', 'review_revision', 'recount_generation', 'reviewed_by', 'reviewer_name', 'reviewed_at', 'review_notes']);
        });
        Schema::table('stock_audits', fn (Blueprint $table) => $table->dropColumn('require_variance_approval'));
    }
};
