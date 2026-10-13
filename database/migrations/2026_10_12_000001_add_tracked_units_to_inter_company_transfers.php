<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inter-company transfers of serial- and batch-tracked units and of kits
 * (App\Services\Organizations\InterCompanyTransferService).
 *
 * - Each serial moved is one row linking the source serial (left behind with
 *   status `transferred`, for history) to the destination serial created or
 *   revived for it.
 * - Each batch drawn on is one row linking the source batch to the
 *   destination batch topped up or created with the same lot number and dates.
 * - A kit line is booked as one line per component; those lines name the kit
 *   on each side and how many kits they belong to.
 *
 * Like the lines, these keep plain ids of the tenant rows on each side (no
 * foreign keys), so the record survives, and never blocks, deleting a serial
 * or batch in either organization; the serial and lot numbers are copied.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inter_company_transfer_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('from_kit_product_id')->nullable();
            $table->unsignedBigInteger('to_kit_product_id')->nullable();
            $table->unsignedInteger('kit_quantity')->nullable();
        });

        Schema::create('inter_company_transfer_serials', function (Blueprint $table) {
            $table->id();
            // Named: the generated names pass MySQL's 64-character limit.
            $table->foreignId('inter_company_transfer_line_id')->constrained('inter_company_transfer_lines', 'id', 'ict_serials_line_foreign')->cascadeOnDelete();
            $table->string('serial_number');
            $table->unsignedBigInteger('from_serial_id')->index('ict_serials_from_serial_index');
            $table->unsignedBigInteger('to_serial_id')->index('ict_serials_to_serial_index');
            $table->timestamps();
        });

        Schema::create('inter_company_transfer_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inter_company_transfer_line_id')->constrained('inter_company_transfer_lines', 'id', 'ict_batches_line_foreign')->cascadeOnDelete();
            $table->string('batch_number');
            $table->date('manufactured_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('from_batch_id')->index('ict_batches_from_batch_index');
            $table->unsignedBigInteger('to_batch_id')->index('ict_batches_to_batch_index');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inter_company_transfer_batches');
        Schema::dropIfExists('inter_company_transfer_serials');

        Schema::table('inter_company_transfer_lines', function (Blueprint $table) {
            $table->dropColumn(['from_kit_product_id', 'to_kit_product_id', 'kit_quantity']);
        });
    }
};
