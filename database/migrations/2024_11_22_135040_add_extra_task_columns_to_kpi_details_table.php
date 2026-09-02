<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddExtraTaskColumnsToKpiDetailsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('kpi_details', function (Blueprint $table) {
            $table->bigInteger('parent_id')->unsigned()->nullable()->after('id');
            $table->foreign('parent_id')->references('id')->on('kpi_details')->onDelete('cascade');
            // Tambahkan kolom untuk menandai entri ekstra tugas
            $table->tinyInteger('is_extra_task')->default(0)->after('value_result'); // 0 = tidak, 1 = ekstra tugas

            // Tambahkan kolom untuk nilai tambahan ekstra tugas
            // $table->double('extra_task_value')->nullable()->after('value_actual');

            // $table->text('notes')->nullable()->after('extra_task_value');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('kpi_details', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropColumn('parent_id');
            $table->dropColumn('is_extra_task');
            // $table->dropColumn('extra_task_value');
            // $table->dropColumn('notes');
        });
    }
}
