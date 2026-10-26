<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_audit_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('count_sequence')->default(0);
        });
        Schema::create('stock_audit_counts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_audit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_audit_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->integer('system_quantity');
            $table->integer('counted_quantity');
            $table->integer('discrepancy');
            $table->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('counted_at');
            $table->boolean('is_snapshot')->default(false);
            $table->unique(['stock_audit_item_id', 'sequence']);
            $table->index(['organization_id', 'stock_audit_id']);
        });
        $this->snapshotExistingCounts();
    }

    private function snapshotExistingCounts(): void
    {
        // Only the latest old count survives in existing installations. Keep
        // it as an explicitly labelled baseline, never invented history.
        $source = DB::table('stock_audit_items as item')
            ->join('stock_audits as audit', 'audit.id', '=', 'item.stock_audit_id')
            ->leftJoin('users as actor', 'actor.id', '=', 'item.counted_by')
            ->whereNotNull('item.counted_quantity')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('stock_audit_counts as history')
                ->whereColumn('history.stock_audit_item_id', 'item.id'))
            ->selectRaw('audit.organization_id, audit.id, item.id, 0, item.system_quantity, item.counted_quantity, item.discrepancy, item.counted_by, actor.name, item.notes, COALESCE(item.counted_at, item.updated_at, item.created_at, CURRENT_TIMESTAMP), ?', [true]);
        DB::table('stock_audit_counts')->insertUsing([
            'organization_id', 'stock_audit_id', 'stock_audit_item_id', 'sequence', 'system_quantity',
            'counted_quantity', 'discrepancy', 'counted_by', 'actor_name', 'notes', 'counted_at', 'is_snapshot',
        ], $source);
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_audit_counts');
        Schema::table('stock_audit_items', fn (Blueprint $table) => $table->dropColumn('count_sequence'));
    }
};
