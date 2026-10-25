<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->json('image_disks')->nullable());
        Schema::table('organizations', fn (Blueprint $table) => $table->string('logo_disk')->nullable());
        Schema::table('shipments', fn (Blueprint $table) => $table->string('label_disk')->nullable());
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('image_disks'));
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn('logo_disk'));
        Schema::table('shipments', fn (Blueprint $table) => $table->dropColumn('label_disk'));
    }
};
