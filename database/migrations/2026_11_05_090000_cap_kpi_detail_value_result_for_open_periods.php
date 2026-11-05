<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Batas atas value_result satu indikator (100%), sama dengan KpiScoringService::RESULT_CAP. */
    private const RESULT_CAP = 1.0;

    /**
     * Run the migrations.
     *
     * Sejak versi ini, value_result indikator RESULT dibatasi maksimal 100% (KpiScoringService::calculateResultValue).
     * Nilai tersimpan > 100% pada periode yang masih bisa diisi (bulan berjalan dan bulan sebelumnya selama masih
     * dalam grace KPI_CHECKLIST_LOCK_DAYS) ikut dibatasi agar tampilan dan rata-rata KPI konsisten dengan aturan baru.
     * Periode yang sudah terkunci tidak disentuh, supaya nilai lama tidak dinilai ulang diam-diam.
     */
    public function up(): void
    {
        if (! Schema::hasTable('kpi_details') || ! Schema::hasTable('kpis')) {
            return;
        }

        $cutoff = $this->earliestOpenPeriodStart();

        $capped = DB::table('kpi_details')
            ->where('value_result', '>', self::RESULT_CAP)
            ->whereIn('kpi_id', DB::table('kpis')->select('id')->where('date', '>=', $cutoff->toDateTimeString()))
            ->update(['value_result' => self::RESULT_CAP]);

        Log::info('Migration cap_kpi_detail_value_result_for_open_periods: value_result > 100% dibatasi ke 100%.', [
            'from_period' => $cutoff->format('Y-m'),
            'rows_capped' => $capped,
        ]);
    }

    /**
     * Reverse the migrations.
     *
     * Tidak dibalik: nilai sebelum dibatasi tidak disimpan. Bila diperlukan, value_result dapat dihitung ulang
     * dari value_plan, value_actual, dan kpi_descriptions.is_negative dengan rumus lama (tanpa batas).
     */
    public function down(): void
    {
        //
    }

    /**
     * Awal bulan paling lama yang checklist-nya masih terbuka untuk non-admin (Kpi::isChecklistLockedFor):
     * bulan berjalan, ditambah bulan sebelumnya selama hari ini <= akhir bulan itu + KPI_CHECKLIST_LOCK_DAYS.
     */
    private function earliestOpenPeriodStart(): Carbon
    {
        $now = Date::now();
        $lockDays = max(0, (int) config('kpi.checklist_lock_days', 5));
        $start = $now->copy()->startOfMonth();

        while (true) {
            $previous = $start->copy()->subMonthNoOverflow();
            $previousDeadline = $previous->copy()->endOfMonth()->addDays($lockDays)->endOfDay();

            if ($now->greaterThan($previousDeadline)) {
                return $start;
            }

            $start = $previous;
        }
    }
};
