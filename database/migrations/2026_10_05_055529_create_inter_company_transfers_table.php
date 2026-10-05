<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock moved between two organizations as one operation: an
 * `inter_company_out` adjustment in the source organization and an
 * `inter_company_in` adjustment in the destination, both referencing the
 * transfer (App\Services\Organizations\InterCompanyTransferService). The
 * transfer belongs to both organizations, so it carries no OrganizationScope.
 *
 * Lines keep plain ids of the products, variants and locations on each side
 * (no foreign keys): the record must survive, and never block, the deletion
 * of a product in either organization.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inter_company_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('from_organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('to_organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 100)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['from_organization_id', 'idempotency_key']);
            $table->index('to_organization_id');
        });

        Schema::create('inter_company_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inter_company_transfer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('from_product_id')->index();
            $table->unsignedBigInteger('from_product_variant_id')->nullable();
            $table->unsignedBigInteger('from_location_id')->nullable();
            $table->unsignedBigInteger('to_product_id')->index();
            $table->unsignedBigInteger('to_product_variant_id')->nullable();
            $table->unsignedBigInteger('to_location_id')->nullable();
            $table->unsignedInteger('quantity');
            $table->foreignId('out_adjustment_id')->nullable()->constrained('stock_adjustments')->nullOnDelete();
            $table->foreignId('in_adjustment_id')->nullable()->constrained('stock_adjustments')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inter_company_transfer_lines');
        Schema::dropIfExists('inter_company_transfers');
    }
};
