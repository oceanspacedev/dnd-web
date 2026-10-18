<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cadangan admin dengan akses data 'selected' (dan badan usahanya) yang dibuat down(). Tanpa cadangan ini,
     * rollback lalu migrate ulang (default kolom 'all') diam-diam menjadikan admin badan usaha tertentu super admin.
     * Sengaja tanpa foreign key agar tidak menghalangi drop tabel users/areas (migrate:reset).
     */
    private const ROLLBACK_BACKUP_TABLE = 'user_area_access_rollback_backup';

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

        $this->restoreSelectedAccess();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->backupSelectedAccess();

        Schema::dropIfExists('user_area_access');

        if (Schema::hasColumn('users', 'data_access')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('data_access');
            });
        }
    }

    /**
     * Simpan admin 'selected' beserta badan usahanya sebelum kolom dan pivot dihapus. Admin 'selected' tanpa
     * badan usaha disimpan dengan area_id null (tetap tidak melihat data apa pun setelah dipulihkan).
     */
    private function backupSelectedAccess(): void
    {
        if (! Schema::hasColumn('users', 'data_access') || ! Schema::hasTable('user_area_access')) {
            return;
        }

        $rows = DB::table('users')
            ->leftJoin('user_area_access', 'user_area_access.user_id', '=', 'users.id')
            ->where('users.data_access', 'selected')
            ->orderBy('users.id')
            ->get(['users.id as user_id', 'user_area_access.area_id'])
            ->map(static fn (object $row): array => [
                'user_id' => (int) $row->user_id,
                'area_id' => $row->area_id === null ? null : (int) $row->area_id,
            ])
            ->all();

        if ($rows === []) {
            return;
        }

        if (! Schema::hasTable(self::ROLLBACK_BACKUP_TABLE)) {
            Schema::create(self::ROLLBACK_BACKUP_TABLE, function (Blueprint $table) {
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('area_id')->nullable();
            });
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table(self::ROLLBACK_BACKUP_TABLE)->insert($chunk);
        }
    }

    /**
     * Pulihkan cadangan down(): admin kembali 'selected' dengan badan usaha yang masih ada, lalu cadangan dihapus.
     * Admin yang dibuat atau dipromosikan selama rollback mendapat default 'all' seperti saat deploy pertama.
     */
    private function restoreSelectedAccess(): void
    {
        if (! Schema::hasTable(self::ROLLBACK_BACKUP_TABLE)) {
            return;
        }

        DB::table('users')
            ->whereIn('id', DB::table(self::ROLLBACK_BACKUP_TABLE)->select('user_id'))
            ->update(['data_access' => 'selected']);

        $now = now();
        $pivot = DB::table(self::ROLLBACK_BACKUP_TABLE.' as backup')
            ->join('users', 'users.id', '=', 'backup.user_id')
            ->join('areas', 'areas.id', '=', 'backup.area_id')
            ->distinct()
            ->get(['backup.user_id', 'backup.area_id'])
            ->map(static fn (object $row): array => [
                'user_id' => (int) $row->user_id,
                'area_id' => (int) $row->area_id,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        foreach (array_chunk($pivot, 500) as $chunk) {
            DB::table('user_area_access')->insertOrIgnore($chunk);
        }

        Schema::drop(self::ROLLBACK_BACKUP_TABLE);
    }
};
