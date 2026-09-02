<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAttendancesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('periode'); // Misalnya format '2024-10' untuk Oktober 2024
            $table->integer('work_days'); // Jumlah hari kerja efektif dalam periode tersebut
            $table->integer('late_less_30')->default(0); // Jumlah keterlambatan < 30 menit
            $table->integer('late_more_30')->default(0); // Jumlah keterlambatan > 30 menit
            $table->integer('sick_days')->default(0); // Jumlah hari sakit
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('attendances');
    }
}
