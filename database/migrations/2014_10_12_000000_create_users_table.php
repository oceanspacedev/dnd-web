<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('nama_lengkap');
            $table->string('username')->unique();
            $table->string('password');
            $table->foreignId('role_id');
            $table->foreignId('area_id');
            $table->foreignId('divisi_id');
            $table->boolean('d')->default(true);
            $table->boolean('wn');
            $table->boolean('wr');
            $table->boolean('mn');
            $table->boolean('mr');
            $table->string('profile_picture')->nullable();
            $table->string('id_notif')->nullable();
            $table->foreignId('approval_id')->nullable();
            $table->rememberToken();
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
        Schema::dropIfExists('users');
    }
}
