<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('users', 'no_hp')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('no_hp')->after('username')->nullable();
            });
        }

        if (!Schema::hasColumn('users', 'email')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('email')->after('no_hp')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // These data-bearing columns may have existed before this migration.
        // Retain them on rollback because the migration cannot safely know
        // whether it originally created each column.
    }
};
