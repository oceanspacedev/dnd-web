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
        Schema::table('weeklies', function (Blueprint $table) {
            $table->string('task_category_id')->nullable()->after('tipe');
            $table->string('task_status_id')->nullable()->after('task_category_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('weeklies', function (Blueprint $table) {
            $table->dropColumn('task_category_id');
            $table->dropColumn('task_status_id');
        });
    }
};
