<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // products references product_categories and product_locations, but
        // their migrations sort after this one. SQLite accepted the dangling
        // foreign keys; MySQL and PostgreSQL reject them, so a fresh install
        // on either failed here. Run those (hasTable-guarded, idempotent)
        // migrations first; when their own turn comes they are no-ops.
        foreach ([
            '2025_10_12_034229_create_product_categories_table.php',
            '2025_10_12_034229_create_product_locations_table.php',
        ] as $dependency) {
            (require __DIR__.'/'.$dependency)->up();
        }

        if (!Schema::hasTable('products')) {
            Schema::create('products', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->string('sku')->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->decimal('price', 10, 2)->default(0);
                $table->decimal('cost', 10, 2)->nullable();
                $table->integer('stock')->default(0);
                $table->integer('min_stock')->default(0);
                $table->integer('max_stock')->nullable();
                $table->string('barcode')->nullable();
                $table->string('image')->nullable();
                $table->foreignId('category_id')->nullable()->constrained('product_categories')->nullOnDelete();
                $table->foreignId('location_id')->nullable()->constrained('product_locations')->nullOnDelete();
                $table->boolean('is_active')->default(true);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['organization_id', 'sku']);
                $table->index('category_id');
                $table->index('location_id');
                $table->index('is_active');
                $table->index('barcode');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
