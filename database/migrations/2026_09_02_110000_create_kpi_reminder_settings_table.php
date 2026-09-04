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
        Schema::create('kpi_reminder_settings', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->enum('type', ['pembuatan_kpi', 'pengisian_kpi'])->default('pengisian_kpi');
            $table->unsignedTinyInteger('deadline_day')->default(25); // 1-31
            $table->json('reminder_days_before')->nullable(); // e.g. [3, 1, 0]
            $table->boolean('send_overdue_reminder')->default(true);
            $table->boolean('send_email')->default(true);
            $table->boolean('send_whatsapp')->default(true);
            $table->string('email_subject')->default('Pengingat KPI - DnD System');
            $table->text('email_body')->nullable();
            $table->text('whatsapp_template')->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kpi_reminder_settings');
    }
};
