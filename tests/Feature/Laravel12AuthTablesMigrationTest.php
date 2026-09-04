<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Laravel12AuthTablesMigrationTest extends TestCase
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

    public function test_auth_tables_are_upgraded_to_current_laravel_and_sanctum_schemas(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->index();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        DB::table('password_reset_tokens')->insert([
            ['email' => 'same@example.test', 'token' => 'old-1'],
            ['email' => 'same@example.test', 'token' => 'old-2'],
        ]);

        $migration = require database_path('migrations/2026_09_04_010300_modernize_auth_tables_for_laravel_12.php');
        $migration->up();

        $this->assertTrue(Schema::hasIndex('password_reset_tokens', ['email'], 'primary'));
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
        $this->assertSame('text', $this->columnType('personal_access_tokens', 'name'));

        DB::table('password_reset_tokens')->insert(['email' => 'user@example.test', 'token' => 'first']);

        try {
            DB::table('password_reset_tokens')->insert(['email' => 'user@example.test', 'token' => 'second']);
            $this->fail('Primary key email seharusnya menolak token duplikat.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $migration->down();

        $this->assertFalse(Schema::hasIndex('password_reset_tokens', ['email'], 'primary'));
        $this->assertTrue(Schema::hasIndex('password_reset_tokens', ['email']));
        $this->assertSame('varchar', $this->columnType('personal_access_tokens', 'name'));
    }

    private function columnType(string $table, string $column): string
    {
        return (string) collect(Schema::getColumns($table))->firstWhere('name', $column)['type_name'];
    }
}
