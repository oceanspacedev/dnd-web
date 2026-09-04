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
        Schema::table('kpi_details', function (Blueprint $table) {
            $table->index('kpi_id');
            $table->index('kpi_description_id');
            $table->index(['kpi_id', 'kpi_description_id']);
            $table->index('parent_id');
            $table->index('is_extra_task');
        });

        Schema::table('kpis', function (Blueprint $table) {
            $table->index('user_id');
            $table->index('kpi_category_id');
            $table->index('kpi_type_id');
            $table->index(['user_id', 'date']);
            $table->index(['kpi_type_id', 'date']);
        });

        Schema::table('kpi_descriptions', function (Blueprint $table) {
            $table->index('kpi_category_id');
            $table->index('description');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index('divisi_id');
            $table->index('role_id');
            $table->index('approval_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('kpi_details', function (Blueprint $table) {
            $table->dropIndex(['kpi_id']);
            $table->dropIndex(['kpi_description_id']);
            $table->dropIndex(['kpi_id', 'kpi_description_id']);
            $table->dropIndex(['parent_id']);
            $table->dropIndex(['is_extra_task']);
        });

        Schema::table('kpis', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->dropIndex(['kpi_category_id']);
            $table->dropIndex(['kpi_type_id']);
            $table->dropIndex(['user_id', 'date']);
            $table->dropIndex(['kpi_type_id', 'date']);
        });

        Schema::table('kpi_descriptions', function (Blueprint $table) {
            $table->dropIndex(['kpi_category_id']);
            $table->dropIndex(['description']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['divisi_id']);
            $table->dropIndex(['role_id']);
            $table->dropIndex(['approval_id']);
        });
    }
};
