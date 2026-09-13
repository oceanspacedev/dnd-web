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
        Schema::create('dailies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->dateTime('date');
            $table->string('task');
            $table->string('time')->nullable();
            $table->boolean('status')->default(false);
            $table->double('ontime')->default(0.0);
            $table->boolean('isplan')->default(true);
            $table->boolean('isupdate')->default(false);
            $table->foreignId('tag_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dailies');
    }
};
