<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Track asynchronously-generated export files so large exports can be
     * queued instead of streamed inline. A row is created when an export
     * exceeds the synchronous row limit; the queued job fills in the stored
     * file path and flips status to completed (or failed), and the user
     * downloads it from the import/export page once ready.
     */
    public function up(): void
    {
        Schema::create('data_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // products | orders | users
            $table->string('filename');
            $table->string('disk');
            $table->string('path')->nullable();
            $table->json('filters')->nullable();
            $table->string('status')->default('pending'); // pending | processing | completed | failed
            $table->unsignedInteger('row_count')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'user_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_exports');
    }
};
