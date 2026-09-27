<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The symbology a product barcode prints as (code128, ean13, upca, ean8,
     * code39). Null means detect: valid EAN-13 / UPC-A values print as such,
     * everything else as Code 128.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('barcode_type', 16)->nullable()->after('barcode');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('barcode_type');
        });
    }
};
