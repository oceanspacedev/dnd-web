<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AlterWeekliesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('weeklies', function (Blueprint $table) {
            $table->string('task_category_id')->nullable()->after('tipe');
            $table->string('task_status_id')->nullable()->after('task_category_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('weeklies', function (Blueprint $table) {
            $table->dropColumn('task_category_id');
            $table->dropColumn('task_status_id');
        });
    }
}
