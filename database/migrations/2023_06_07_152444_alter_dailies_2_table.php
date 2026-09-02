<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AlterDailies2Table extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('dailies', function (Blueprint $table) {
            $table->bigInteger('value_plan')->after('task_status_id')->nullable();
            $table->bigInteger('value_actual')->after('value_plan')->nullable();
            $table->boolean('status_result')->after('value_actual')->nullable();
            $table->double('value')->after('status_result')->default(0);
            $table->string('tipe')->after('time')->default('NON');
            $table->bigInteger('add_id')->after('tag_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('dailies', function (Blueprint $table) {
            $table->dropColumn('value_plan');
            $table->dropColumn('value_actual');
            $table->dropColumn('status_result');
            $table->dropColumn('value');
            $table->dropColumn('tipe');
            $table->dropColumn('add_id');
        });
    }
}
