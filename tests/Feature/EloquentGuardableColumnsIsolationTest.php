<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Depends;
use ReflectionProperty;
use Tests\Concerns\CreatesDataAccessFixtures;
use Tests\TestCase;

/**
 * Eloquent menyimpan daftar kolom tabel per model secara static (Model::$guardableColumns) untuk model ber-$guarded.
 * Tes yang membangun tabel users versi kecil (mis. KpiReminderCommandTest) tidak boleh membuat tes berikutnya
 * diam-diam membuang username/password saat User::create (NOT NULL users.username pada urutan acak).
 */
class EloquentGuardableColumnsIsolationTest extends TestCase
{
    use CreatesDataAccessFixtures;

    protected function tearDown(): void
    {
        $this->resetAccessMemos();
        parent::tearDown();
    }

    public function test_a_reduced_users_schema_fills_the_guardable_column_cache(): void
    {
        $this->useFreshInMemoryDatabase();
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('nama_lengkap');
            $table->timestamps();
            $table->softDeletes();
        });

        $user = User::query()->create(['nama_lengkap' => 'Kolom Terbatas', 'username' => 'dibuang']);

        $this->assertNull($user->getAttribute('username'));
        $this->assertSame(['id', 'nama_lengkap', 'created_at', 'updated_at', 'deleted_at'], $this->guardableColumns()[User::class] ?? null);
    }

    #[Depends('test_a_reduced_users_schema_fills_the_guardable_column_cache')]
    public function test_the_next_test_starts_with_an_empty_cache_and_keeps_every_column(): void
    {
        $this->assertSame([], $this->guardableColumns());

        $this->useFreshInMemoryDatabase();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        // Jalur yang dipakai ratusan tes DataAccess/Scoring (User::query()->create dengan username, password, role_id).
        $user = $this->fixtureUser('kolom_lengkap', 'STAFF', $this->fixtureArea('COO'));

        $this->assertSame('kolom_lengkap', $user->username);
        $this->assertNotNull($user->role_id);
    }

    /**
     * Koneksi sqlite :memory: sendiri (tanpa RefreshDatabase, yang berbagi PDO in-memory dengan tes lain).
     */
    private function useFreshInMemoryDatabase(): void
    {
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
    }

    /**
     * @return array<class-string, list<string>>
     */
    private function guardableColumns(): array
    {
        return (new ReflectionProperty(Model::class, 'guardableColumns'))->getValue();
    }
}
