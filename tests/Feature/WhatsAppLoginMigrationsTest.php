<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class WhatsAppLoginMigrationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('no_hp')->nullable();
        });
    }

    public function test_migrations_use_no_hp_as_the_only_user_number_and_keep_otps_separate(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('whatsapp_number')->nullable()->unique();
            $table->timestamp('whatsapp_verified_at')->nullable();
        });

        DB::table('users')->insert([
            ['id' => 1, 'no_hp' => '+62 812-3456-7890', 'whatsapp_number' => '6281111111111'],
            ['id' => 2, 'no_hp' => '0813 4567 8901', 'whatsapp_number' => null],
            ['id' => 3, 'no_hp' => '6281498765432', 'whatsapp_number' => null],
            ['id' => 4, 'no_hp' => null, 'whatsapp_number' => null],
            ['id' => 5, 'no_hp' => '   ', 'whatsapp_number' => null],
        ]);

        $credentialMigration = require database_path('migrations/2026_09_04_000100_use_no_hp_for_whatsapp_login.php');
        $otpMigration = require database_path('migrations/2026_09_04_000000_create_whatsapp_otps_table.php');

        $otpMigration->up();
        $credentialMigration->up();
        $credentialMigration->up();

        $this->assertTrue(Schema::hasIndex('users', 'users_no_hp_unique'));
        $this->assertFalse(Schema::hasColumn('users', 'whatsapp_number'));
        $this->assertFalse(Schema::hasColumn('users', 'whatsapp_verified_at'));
        $this->assertTrue(Schema::hasColumns('whatsapp_otps', [
            'user_id',
            'whatsapp_number',
            'purpose',
            'otp_hash',
            'expires_at',
            'verified_at',
            'attempt_count',
        ]));

        $users = DB::table('users')->orderBy('id')->get()->keyBy('id');
        $this->assertSame('081234567890', $users[1]->no_hp);
        $this->assertSame('081345678901', $users[2]->no_hp);
        $this->assertSame('081498765432', $users[3]->no_hp);
        $this->assertNull($users[4]->no_hp);
        $this->assertNull($users[5]->no_hp);

        try {
            DB::table('users')->where('id', 2)->update(['no_hp' => '081234567890']);
            $this->fail('No. HP yang sama seharusnya ditolak database.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        DB::table('whatsapp_otps')->insert([
            'user_id' => 1,
            'whatsapp_number' => '6281234567890',
            'purpose' => 'login',
            'otp_hash' => 'hashed',
            'expires_at' => now()->addMinute(),
            'attempt_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $credentialMigration->down();
        $otpMigration->down();

        $this->assertFalse(Schema::hasIndex('users', 'users_no_hp_unique'));
        $this->assertFalse(Schema::hasTable('whatsapp_otps'));
        $this->assertSame('081234567890', DB::table('users')->where('id', 1)->value('no_hp'));
    }

    public function test_no_hp_migration_rejects_normalized_duplicates_without_partial_changes(): void
    {
        DB::table('users')->insert([
            ['id' => 1, 'no_hp' => '+62 812-3456-7890'],
            ['id' => 2, 'no_hp' => '0812 3456 7890'],
        ]);

        $migration = require database_path('migrations/2026_09_04_000100_use_no_hp_for_whatsapp_login.php');

        try {
            $migration->up();
            $this->fail('Duplikat No. HP setelah normalisasi seharusnya membatalkan migrasi.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('nomor duplikat', $exception->getMessage());
        }

        $this->assertSame('+62 812-3456-7890', DB::table('users')->where('id', 1)->value('no_hp'));
        $this->assertSame('0812 3456 7890', DB::table('users')->where('id', 2)->value('no_hp'));
        $this->assertFalse(Schema::hasIndex('users', 'users_no_hp_unique'));
    }

    public function test_no_hp_migration_rejects_invalid_legacy_values_without_deleting_them(): void
    {
        DB::table('users')->insert(['id' => 1, 'no_hp' => 'nomor-lama-tidak-valid']);
        $migration = require database_path('migrations/2026_09_04_000100_use_no_hp_for_whatsapp_login.php');

        try {
            $migration->up();
            $this->fail('No. HP tidak valid seharusnya membatalkan migrasi.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('user ID: 1', $exception->getMessage());
        }

        $this->assertSame(
            'nomor-lama-tidak-valid',
            DB::table('users')->where('id', 1)->value('no_hp'),
        );
        $this->assertFalse(Schema::hasIndex('users', 'users_no_hp_unique'));
    }
}
