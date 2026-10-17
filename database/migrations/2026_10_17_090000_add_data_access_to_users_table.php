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
        // Akses data hanya berlaku untuk role ADMIN. Default 'all' menjaga admin lama tetap melihat seluruh badan usaha.
        if (! Schema::hasColumn('users', 'data_access')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('data_access', 20)->default('all')->after('role_id');
            });
        }

        // Daftar badan usaha (areas) yang boleh diakses admin dengan akses data 'selected'.
        if (! Schema::hasTable('user_area_access')) {
            Schema::create('user_area_access', function (Blueprint $table) {
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('area_id')->constrained('areas')->cascadeOnDelete();
                $table->timestamps();

                $table->primary(['user_id', 'area_id']);
                $table->index('area_id', 'user_area_access_area_id_index');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_area_access');

        if (Schema::hasColumn('users', 'data_access')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('data_access');
            });
        }
    }
};
