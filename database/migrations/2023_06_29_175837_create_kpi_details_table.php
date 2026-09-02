<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateKpiDetailsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
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
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('kpi_details');
    }
}
