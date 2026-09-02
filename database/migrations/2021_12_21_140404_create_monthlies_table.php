<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMonthliesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('monthlies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('task');
            $table->timestamp('date')->nullable();
            $table->string('tipe');
            $table->bigInteger('value_plan')->nullable()->change();
            $table->bigInteger('value_actual')->nullable()->change();
            $table->boolean('status_non')->nullable();
            $table->boolean('status_result')->nullable();
            $table->double('value')->default(0);
            $table->boolean('is_add')->default(false);
            $table->boolean('is_update')->default(false);
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
        Schema::dropIfExists('monthlies');
    }
}
