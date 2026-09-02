<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class KpiReminderMigrationsTest extends TestCase
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
    }

    public function test_contact_migration_never_removes_preexisting_contact_columns_or_data(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username');
            $table->string('email')->nullable();
        });
        DB::table('users')->insert([
            'id' => 1,
            'username' => 'existing-user',
            'email' => 'existing@example.test',
        ]);

        $migration = require database_path('migrations/2026_09_02_100000_add_no_hp_and_email_to_users_table.php');
        $migration->up();
        $migration->down();

        $this->assertTrue(Schema::hasColumns('users', ['no_hp', 'email']));
        $this->assertSame(
            'existing@example.test',
            DB::table('users')->where('id', 1)->value('email'),
        );
    }

    public function test_dedicated_reminder_cache_tables_are_reversible(): void
    {
        $migration = require database_path('migrations/2026_09_02_120000_create_kpi_reminder_cache_tables.php');

        $migration->up();
        $this->assertTrue(Schema::hasTable('kpi_reminder_cache'));
        $this->assertTrue(Schema::hasTable('kpi_reminder_cache_locks'));

        Cache::forgetDriver('kpi_reminders');
        $store = Cache::store('kpi_reminders')->getStore();
        $this->assertInstanceOf(LockProvider::class, $store);
        $lock = $store->lock('integration-test', 10);
        $this->assertTrue($lock->get());
        $lock->release();

        $migration->down();
        $this->assertFalse(Schema::hasTable('kpi_reminder_cache'));
        $this->assertFalse(Schema::hasTable('kpi_reminder_cache_locks'));
    }
}
