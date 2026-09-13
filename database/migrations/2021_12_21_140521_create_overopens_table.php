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
        Schema::create('overopens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->foreignId('atasan')->nullable();
            $table->integer('week');
            $table->integer('year');
            $table->integer('daily')->nullable();
            $table->integer('weekly')->nullable();
            $table->integer('monthly')->nullable();
            $table->integer('point');
            $table->string('keterangan')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('overopens');
    }
};
