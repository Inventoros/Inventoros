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
        Schema::create('variant_location_stocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('product_locations')->cascadeOnDelete();
            $table->integer('quantity')->default(0);
            $table->timestamps();
            $table->unique(['product_variant_id', 'location_id'], 'variant_location_unique');
            $table->index(['organization_id', 'location_id']);
        });

        DB::table('variant_location_stocks')->insertUsing(
            ['organization_id', 'product_variant_id', 'location_id', 'quantity', 'created_at', 'updated_at'],
            DB::table('product_variants as v')
                ->join('products as p', 'p.id', '=', 'v.product_id')
                ->whereColumn('p.organization_id', 'v.organization_id')
                ->whereNull('v.deleted_at')->whereNull('p.deleted_at')->whereNotNull('p.location_id')
                ->select(['v.organization_id', 'v.id', 'p.location_id', 'v.stock'])
                ->selectRaw('CURRENT_TIMESTAMP, CURRENT_TIMESTAMP'),
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('variant_location_stocks');
    }
};
