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
                'notes' => 'Aturan sebelum November 2026. Kolom Sakit berisi sakit dan izin sehingga tetap dipenalti 5 poin per hari.',
            ],
            [
                'name' => 'Aturan Kehadiran November 2026',
                'effective_from' => '2026-11',
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

        foreach ($rules as $rule) {
            if (DB::table('scoring_rules')->where('effective_from', $rule['effective_from'])->exists()) {
                continue;
            }

            DB::table('scoring_rules')->insert($rule + [
                'engine' => 'standard',
                'created_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scoring_rules');
    }
};
