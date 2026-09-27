<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Scheduled email delivery of saved (builder) reports. next_run_at is
     * stored in UTC and computed from the organization's time zone; the
     * scheduler command picks rows whose next_run_at has passed.
     */
    public function up(): void
    {
        Schema::create('report_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('saved_report_id')->constrained('saved_reports')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('frequency', 10); // daily, weekly, monthly
            $table->unsignedTinyInteger('day_of_week')->nullable(); // 0 = Sunday .. 6 = Saturday
            $table->unsignedTinyInteger('day_of_month')->nullable(); // 1..31, clamped to the month's length
            $table->string('time_of_day', 5)->default('08:00'); // HH:MM in the organization's time zone
            $table->string('format', 5)->default('csv'); // csv, xlsx, pdf
            $table->json('recipients');
            $table->boolean('is_active')->default(true);
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_status', 20)->nullable(); // sent, skipped, failed
            $table->timestamps();

            $table->index(['is_active', 'next_run_at']);
            $table->index(['organization_id', 'saved_report_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedules');
    }
};
