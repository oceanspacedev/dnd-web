<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AlterMonthliesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('monthlies', function (Blueprint $table) {
            $table->bigInteger('tag_id')->after('is_update')->nullable();
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
        Schema::table('monthlies', function (Blueprint $table) {
            $table->dropColumn('tag_id');
            $table->dropColumn('add_id');
        });
    }
}
