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
        Schema::create('kpi_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kpi_id');
            $table->foreignId('kpi_description_id');
            $table->string('count_type')->default('NON');
            $table->double('value_plan')->nullable();
            $table->double('value_actual')->nullable();
            $table->double('value_result')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kpi_details');
    }
};
