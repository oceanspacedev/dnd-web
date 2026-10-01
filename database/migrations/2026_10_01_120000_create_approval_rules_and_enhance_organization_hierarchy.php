<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Tambah level hirarki pada roles
        if (! Schema::hasColumn('roles', 'level')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->unsignedInteger('level')->default(10)->after('name');
            });

            // Set default level yang logis untuk role bawaan jika ada
            DB::table('roles')->where('name', 'ADMIN')->update(['level' => 100]);
            DB::table('roles')->where('name', 'BOD')->update(['level' => 60]);
            DB::table('roles')->where('name', 'CHIEF')->update(['level' => 50]);
            DB::table('roles')->where('name', 'MANAGER')->update(['level' => 40]);
            DB::table('roles')->where('name', 'COORDINATOR')->update(['level' => 30]);
            DB::table('roles')->where('name', 'TEAM LEADER')->update(['level' => 20]);
            DB::table('roles')->where('name', 'STAFF')->update(['level' => 10]);
        }

        // 2. Tambah manager_id (Kepala Divisi) pada master divisis
        if (! Schema::hasColumn('divisis', 'manager_id')) {
            Schema::table('divisis', function (Blueprint $table) {
                $table->foreignId('manager_id')->nullable()->after('area_id')->constrained('users')->nullOnDelete();
            });
        }

        // 3. Buat tabel approval_rules (Matriks Aturan Approval)
        if (! Schema::hasTable('approval_rules')) {
            Schema::create('approval_rules', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->foreignId('area_id')->nullable()->constrained('areas')->nullOnDelete();
                $table->foreignId('divisi_id')->nullable()->constrained('divisis')->nullOnDelete();
                $table->foreignId('position_id')->nullable()->constrained('positions')->nullOnDelete();
                $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
                $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
                $table->integer('priority')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['divisi_id', 'is_active']);
                $table->index(['area_id', 'is_active']);
                $table->index(['position_id', 'is_active']);
                $table->index(['role_id', 'is_active']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approval_rules');

        if (Schema::hasColumn('divisis', 'manager_id')) {
            Schema::table('divisis', function (Blueprint $table) {
                $table->dropForeign(['manager_id']);
                $table->dropColumn('manager_id');
            });
        }

        if (Schema::hasColumn('roles', 'level')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->dropColumn('level');
            });
        }
    }
};
