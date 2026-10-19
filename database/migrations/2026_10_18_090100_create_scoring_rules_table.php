<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Periode mulai aturan kehadiran baru (izin, mangkir, cuti dicatat sendiri; sakit tanpa penalti). */
    private const IZIN_RULE_START = '2026-11';

    /** Deskripsi awal komponen sick_days (migration attendance_components) yang menyebut periode mulai itu. */
    private const SICK_DESCRIPTION = 'Sebelum November 2026 kolom ini berisi sakit dan izin (Sakit/Izin).';

    private const MONTH_NAMES = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Aturan penilaian kinerja berversi: periode 'Y-m' memakai versi terakhir dengan effective_from <= periode.
        if (! Schema::hasTable('scoring_rules')) {
            Schema::create('scoring_rules', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->char('effective_from', 7)->unique('scoring_rules_effective_from_unique'); // 'Y-m'
                $table->string('engine', 50)->default('standard');
                $table->decimal('kpi_weight', 6, 2);
                $table->decimal('attendance_weight', 6, 2);
                $table->decimal('review_weight', 6, 2);
                $table->unsignedSmallInteger('review_max_points')->default(20);
                // Daftar {component, reduces_base, penalty_points} per kode attendance_components.
                $table->json('attendance_rules');
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        // Nilai literal (bukan konstanta aplikasi) agar perubahan kode tidak mengubah versi yang sudah berlaku.
        $now = now();
        $v2Start = $this->izinRuleStart();
        $v2Label = $this->label($v2Start);
        $rules = [
            [
                'name' => 'Aturan Lama',
                // Sentinel: semua periode sebelum versi berikutnya (termasuk data sampah 1900-01 / 1970-01).
                'effective_from' => '2000-01',
                'kpi_weight' => 70,
                'attendance_weight' => 15,
                'review_weight' => 15,
                'review_max_points' => 20,
                'attendance_rules' => json_encode([
                    ['component' => 'late_less_30', 'reduces_base' => true, 'penalty_points' => 1],
                    ['component' => 'late_more_30', 'reduces_base' => true, 'penalty_points' => 3],
                    ['component' => 'sick_days', 'reduces_base' => true, 'penalty_points' => 5],
                ]),
                'notes' => "Aturan sebelum {$v2Label}. Kolom Sakit berisi sakit dan izin sehingga tetap dipenalti 5 poin per hari.",
            ],
            [
                'name' => "Aturan Kehadiran {$v2Label}",
                'effective_from' => $v2Start,
                'kpi_weight' => 70,
                'attendance_weight' => 15,
                'review_weight' => 15,
                'review_max_points' => 20,
                'attendance_rules' => json_encode([
                    ['component' => 'late_less_30', 'reduces_base' => true, 'penalty_points' => 1],
                    ['component' => 'late_more_30', 'reduces_base' => true, 'penalty_points' => 3],
                    ['component' => 'sick_days', 'reduces_base' => true, 'penalty_points' => 0],
                    ['component' => 'izin', 'reduces_base' => false, 'penalty_points' => 5],
                    ['component' => 'mangkir', 'reduces_base' => false, 'penalty_points' => 5],
                    ['component' => 'cuti_tahunan', 'reduces_base' => false, 'penalty_points' => 0],
                    ['component' => 'cuti_lainnya', 'reduces_base' => false, 'penalty_points' => 0],
                ]),
                'notes' => 'Telat > 5 menit 1 poin, telat > 30 menit 3 poin, izin dan mangkir 5 poin per hari; sakit dan cuti tidak mengurangi poin.',
            ],
        ];

        foreach ($rules as $index => $rule) {
            $exists = $index === 0
                ? DB::table('scoring_rules')->where('effective_from', $rule['effective_from'])->exists()
                // Aturan baru sudah ada (mungkin digeser oleh izinRuleStart() pada jalan sebelumnya): jangan tambah lagi.
                : DB::table('scoring_rules')->where('effective_from', '>=', self::IZIN_RULE_START)->exists();

            if ($exists) {
                continue;
            }

            DB::table('scoring_rules')->insert($rule + [
                'engine' => 'standard',
                'created_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($index === 1 && $v2Start !== self::IZIN_RULE_START && Schema::hasTable('attendance_components')) {
                DB::table('attendance_components')
                    ->where('code', 'sick_days')
                    ->where('description', self::SICK_DESCRIPTION)
                    ->update(['description' => "Sebelum {$v2Label} kolom ini berisi sakit dan izin (Sakit/Izin).", 'updated_at' => $now]);
            }
        }
    }

    /**
     * Periode mulai aturan kehadiran baru: IZIN_RULE_START, atau bulan sesudah periode kehadiran terakhir yang sudah
     * tercatat sebelum migration ini berjalan (deploy terlambat). Data itu diisi dengan arti kolom lama (Sakit =
     * sakit + izin, tanpa izin/mangkir sendiri), jadi tidak boleh dinilai ulang diam-diam dengan aturan baru.
     * Periode sesudah bulan deploy (salah ketik) tidak menggeser aturan. Tanpa data itu, aturan baru tetap mulai
     * IZIN_RULE_START walaupun bulan itu sudah lewat (tidak ada data yang dinilai ulang). Versi yang digeser masih
     * bisa diubah super admin selama belum terkunci (mis. dipindah ke bulan berjalan).
     */
    private function izinRuleStart(): string
    {
        if (! Schema::hasTable('attendances')) {
            return self::IZIN_RULE_START;
        }

        $current = now()->format('Y-m');
        $start = self::IZIN_RULE_START;
        $periodes = DB::table('attendances')
            ->when(Schema::hasColumn('attendances', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
            ->where('periode', '>=', substr(self::IZIN_RULE_START, 0, 4))
            ->distinct()
            ->pluck('periode');

        foreach ($periodes as $periode) {
            if (! is_string($periode) || preg_match('/^\s*(\d{4})-(\d{1,2})(?!\d)/', $periode, $matches) !== 1) {
                continue;
            }

            $year = (int) $matches[1];
            $month = (int) $matches[2];

            if ($month < 1 || $month > 12) {
                continue;
            }

            $normalized = sprintf('%04d-%02d', $year, $month);

            if (strcmp($normalized, self::IZIN_RULE_START) < 0 || strcmp($normalized, $current) > 0) {
                continue;
            }

            $next = $month === 12 ? sprintf('%04d-01', $year + 1) : sprintf('%04d-%02d', $year, $month + 1);

            if (strcmp($next, $start) > 0) {
                $start = $next;
            }
        }

        if ($start !== self::IZIN_RULE_START) {
            // Deploy terlambat: beri tahu yang men-deploy bahwa aturan baru mulai lebih lambat dari rencana.
            Log::warning("Aturan kehadiran baru mulai {$start}, bukan ".self::IZIN_RULE_START.', karena kehadiran periode sebelumnya sudah tercatat dengan arti kolom lama. Ubah bulan berlakunya di Settings > Aturan Penilaian bila perlu.');
        }

        return $start;
    }

    /**
     * '2026-11' => 'November 2026'.
     */
    private function label(string $periode): string
    {
        return self::MONTH_NAMES[(int) substr($periode, 5, 2) - 1].' '.substr($periode, 0, 4);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scoring_rules');
    }
};
