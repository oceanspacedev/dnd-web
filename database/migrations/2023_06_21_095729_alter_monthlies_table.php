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
        Schema::table('monthlies', function (Blueprint $table) {
            $table->bigInteger('tag_id')->after('is_update')->nullable();
            $table->bigInteger('add_id')->after('tag_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('monthlies', function (Blueprint $table) {
            $table->dropColumn('tag_id');
            $table->dropColumn('add_id');
        });
    }
};
