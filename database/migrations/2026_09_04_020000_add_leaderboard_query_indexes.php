<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cutpoints', function (Blueprint $table): void {
            $table->index(['user_id', 'periode', 'deleted_at'], 'cutpoints_user_period_active_index');
        });

        Schema::table('attendances', function (Blueprint $table): void {
            $table->index(['user_id', 'periode', 'deleted_at'], 'attendances_user_period_active_index');
        });

        Schema::table('employee_reviews', function (Blueprint $table): void {
            $table->index(['user_id', 'periode', 'deleted_at'], 'reviews_user_period_active_index');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->index('area_id', 'users_area_id_index');
        });

        Schema::table('dailies', function (Blueprint $table): void {
            $table->index(['date', 'deleted_at', 'status'], 'dailies_date_active_status_index');
        });

        Schema::table('requests', function (Blueprint $table): void {
            $table->index(['status', 'deleted_at'], 'requests_status_active_index');
        });
    }

    public function down(): void
    {
        Schema::table('cutpoints', function (Blueprint $table): void {
            $table->dropIndex('cutpoints_user_period_active_index');
        });

        Schema::table('attendances', function (Blueprint $table): void {
            $table->dropIndex('attendances_user_period_active_index');
        });

        Schema::table('employee_reviews', function (Blueprint $table): void {
            $table->dropIndex('reviews_user_period_active_index');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_area_id_index');
        });

        Schema::table('dailies', function (Blueprint $table): void {
            $table->dropIndex('dailies_date_active_status_index');
        });

        Schema::table('requests', function (Blueprint $table): void {
            $table->dropIndex('requests_status_active_index');
        });
    }
};
