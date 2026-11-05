<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Migration pembatas value_result 100% hanya menyentuh periode yang masih terbuka
 * (bulan berjalan + bulan sebelumnya selama masih dalam grace KPI_CHECKLIST_LOCK_DAYS).
 */
class KpiResultCapMigrationTest extends TestCase
{
    private const MIGRATION = 'migrations/2026_11_05_090000_cap_kpi_detail_value_result_for_open_periods.php';

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
        config()->set('kpi.checklist_lock_days', 5);

        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::create('kpis', function (Blueprint $table) {
            $table->id();
            $table->dateTime('date');
        });
        Schema::create('kpi_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kpi_id');
            $table->string('count_type')->default('NON');
            $table->double('value_result')->nullable();
        });

        DB::table('kpis')->insert([
            ['id' => 1, 'date' => '2026-11-01 00:00:00'],
            ['id' => 2, 'date' => '2026-10-15 00:00:00'],
            ['id' => 3, 'date' => '2026-09-15 00:00:00'],
        ]);
        DB::table('kpi_details')->insert([
            ['id' => 11, 'kpi_id' => 1, 'count_type' => 'RESULT', 'value_result' => 6.0],
            ['id' => 12, 'kpi_id' => 1, 'count_type' => 'RESULT', 'value_result' => 0.5],
            ['id' => 13, 'kpi_id' => 1, 'count_type' => 'NON', 'value_result' => 1.0],
            ['id' => 14, 'kpi_id' => 1, 'count_type' => 'RESULT', 'value_result' => null],
            ['id' => 21, 'kpi_id' => 2, 'count_type' => 'RESULT', 'value_result' => 2.0],
            ['id' => 31, 'kpi_id' => 3, 'count_type' => 'RESULT', 'value_result' => 3.0],
        ]);
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    public function test_caps_current_month_and_previous_month_while_previous_month_is_still_in_grace(): void
    {
        // 5 Nov <= 31 Okt + 5 hari grace: Oktober masih terbuka, September sudah terkunci.
        Date::setTestNow('2026-11-05 10:00:00');

        $migration = require database_path(self::MIGRATION);
        $migration->up();

        $this->assertSame([
            11 => 1.0,
            12 => 0.5,
            13 => 1.0,
            14 => null,
            21 => 1.0,
            31 => 3.0,
        ], $this->storedResults());
    }

    public function test_leaves_previous_month_alone_once_its_grace_period_has_passed(): void
    {
        // 6 Nov > 31 Okt + 5 hari grace: hanya November yang terbuka.
        Date::setTestNow('2026-11-06 10:00:00');

        $migration = require database_path(self::MIGRATION);
        $migration->up();

        $this->assertSame([
            11 => 1.0,
            12 => 0.5,
            13 => 1.0,
            14 => null,
            21 => 2.0,
            31 => 3.0,
        ], $this->storedResults());
    }

    public function test_is_a_noop_without_the_kpi_tables(): void
    {
        Schema::drop('kpi_details');

        $migration = require database_path(self::MIGRATION);
        $migration->up();

        $this->assertFalse(Schema::hasTable('kpi_details'));
    }

    /**
     * @return array<int, float|null>
     */
    private function storedResults(): array
    {
        return DB::table('kpi_details')
            ->orderBy('id')
            ->pluck('value_result', 'id')
            ->map(fn ($value) => $value === null ? null : (float) $value)
            ->all();
    }
}
