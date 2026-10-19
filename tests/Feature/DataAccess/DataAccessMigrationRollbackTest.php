<?php

namespace Tests\Feature\DataAccess;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Rollback lalu migrate ulang migration akses data tidak boleh menjadikan admin badan usaha tertentu ('selected')
 * super admin lewat default kolom 'all'.
 */
class DataAccessMigrationRollbackTest extends TestCase
{
    private const BACKUP_TABLE = 'user_area_access_rollback_backup';

    public function test_rollback_and_re_migrate_keep_admins_limited_to_selected_badan_usaha(): void
    {
        $this->onScratchConnection(function (): void {
            $migration = $this->migration();
            $migration->up();

            DB::table('areas')->insert([['id' => 1, 'name' => 'COO'], ['id' => 2, 'name' => 'ULS'], ['id' => 3, 'name' => 'GMD']]);
            DB::table('users')->insert([
                ['id' => 1, 'username' => 'super', 'data_access' => 'all'],
                ['id' => 2, 'username' => 'ojan', 'data_access' => 'selected'],
                ['id' => 3, 'username' => 'kosong', 'data_access' => 'selected'],
                ['id' => 4, 'username' => 'dua', 'data_access' => 'selected'],
                ['id' => 5, 'username' => 'staff', 'data_access' => 'all'],
            ]);
            DB::table('user_area_access')->insert([
                ['user_id' => 2, 'area_id' => 2],
                ['user_id' => 4, 'area_id' => 1],
                ['user_id' => 4, 'area_id' => 3],
            ]);

            $migration->down();

            $this->assertFalse(Schema::hasColumn('users', 'data_access'));
            $this->assertFalse(Schema::hasTable('user_area_access'));
            $this->assertTrue(Schema::hasTable(self::BACKUP_TABLE));

            // Selama rollback kode lama tetap berjalan: badan usaha dihapus, karyawan baru dibuat.
            DB::table('areas')->where('id', 3)->delete();
            DB::table('users')->insert(['id' => 6, 'username' => 'baru']);

            $migration->up();

            $this->assertSame(
                [1 => 'all', 2 => 'selected', 3 => 'selected', 4 => 'selected', 5 => 'all', 6 => 'all'],
                DB::table('users')->orderBy('id')->pluck('data_access', 'id')->all(),
            );
            $this->assertSame(
                [[2, 2], [4, 1]],
                DB::table('user_area_access')->orderBy('user_id')->orderBy('area_id')->get()
                    ->map(static fn (object $row): array => [(int) $row->user_id, (int) $row->area_id])
                    ->all(),
            );
            $this->assertFalse(Schema::hasTable(self::BACKUP_TABLE));

            // Migrate ulang tanpa cadangan tidak mengubah apa pun.
            $migration->up();
            $this->assertSame(2, DB::table('user_area_access')->count());
        });
    }

    public function test_rollback_without_selected_admins_leaves_no_backup_table(): void
    {
        $this->onScratchConnection(function (): void {
            $migration = $this->migration();
            $migration->up();
            DB::table('users')->insert(['id' => 1, 'username' => 'super', 'data_access' => 'all']);

            $migration->down();

            $this->assertFalse(Schema::hasTable(self::BACKUP_TABLE));
            $this->assertFalse(Schema::hasColumn('users', 'data_access'));
            $this->assertFalse(Schema::hasTable('user_area_access'));
        });
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_17_090000_add_data_access_to_users_table.php');
    }

    /**
     * Schema users/areas minimal di koneksi sqlite terpisah, agar database tes utama tidak tersentuh.
     */
    private function onScratchConnection(callable $callback): void
    {
        config()->set('database.connections.data_access_migration', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection('data_access_migration');

        try {
            Schema::create('areas', function (Blueprint $table) {
                $table->id();
                $table->string('name');
            });
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('username');
                $table->unsignedBigInteger('role_id')->nullable();
            });

            $callback();
        } finally {
            DB::setDefaultConnection($previous);
            DB::purge('data_access_migration');
        }
    }
}
