<?php

declare(strict_types=1);

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
        Schema::create('saved_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('data_source'); // products, orders, stock_adjustments, customers, suppliers, purchase_orders
            $table->json('columns'); // array of selected column keys
            $table->json('filters')->nullable(); // array of {field, operator, value}
            $table->json('sort')->nullable(); // {field, direction}
            $table->string('chart_type')->nullable(); // bar, line, pie, or null for table only
            $table->string('chart_field')->nullable(); // which field to chart
            $table->boolean('is_shared')->default(false); // visible to other org users
            $table->timestamps();

            $table->index(['organization_id', 'created_by']);
            $table->index(['organization_id', 'is_shared']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('saved_reports');
    }
};
