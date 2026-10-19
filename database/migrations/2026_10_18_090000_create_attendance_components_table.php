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
        // 1. Master komponen kehadiran: komponen baru cukup ditambah datanya, tanpa ubah kode.
        if (! Schema::hasTable('attendance_components')) {
            Schema::create('attendance_components', function (Blueprint $table) {
                $table->id();
                $table->string('code', 50)->unique('attendance_components_code_unique');
                $table->string('name');
                $table->string('unit', 20)->default('hari'); // 'hari' | 'kejadian'
                $table->text('description')->nullable();
                // Kolom lama di tabel attendances (late_less_30, late_more_30, sick_days); null = disimpan di component_counts.
                $table->string('legacy_column', 50)->nullable()->unique('attendance_components_legacy_column_unique');
                $table->integer('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        // Nilai literal (bukan konstanta aplikasi) agar perubahan kode tidak mengubah data awal.
        $now = now();
        $components = [
            [
                'code' => 'late_less_30',
                'name' => 'Terlambat ≤ 30 menit',
                'unit' => 'kejadian',
                'description' => 'Sejak November 2026 hanya keterlambatan lebih dari 5 menit yang dihitung.',
                'legacy_column' => 'late_less_30',
                'sort_order' => 1,
            ],
            [
                'code' => 'late_more_30',
                'name' => 'Terlambat > 30 menit',
                'unit' => 'kejadian',
                'description' => null,
                'legacy_column' => 'late_more_30',
                'sort_order' => 2,
            ],
            [
                'code' => 'sick_days',
                'name' => 'Sakit',
                'unit' => 'hari',
                'description' => 'Sebelum November 2026 kolom ini berisi sakit dan izin (Sakit/Izin).',
                'legacy_column' => 'sick_days',
                'sort_order' => 3,
            ],
            [
                'code' => 'izin',
                'name' => 'Izin',
                'unit' => 'hari',
                'description' => null,
                'legacy_column' => null,
                'sort_order' => 4,
            ],
            [
                'code' => 'mangkir',
                'name' => 'Mangkir (tanpa keterangan)',
                'unit' => 'hari',
                'description' => null,
                'legacy_column' => null,
                'sort_order' => 5,
            ],
            [
                'code' => 'cuti_tahunan',
                'name' => 'Cuti tahunan',
                'unit' => 'hari',
                'description' => null,
                'legacy_column' => null,
                'sort_order' => 6,
            ],
            [
                'code' => 'cuti_lainnya',
                'name' => 'Cuti lainnya',
                'unit' => 'hari',
                'description' => null,
                'legacy_column' => null,
                'sort_order' => 7,
            ],
        ];

        foreach ($components as $component) {
            if (DB::table('attendance_components')->where('code', $component['code'])->exists()) {
                continue;
            }

            DB::table('attendance_components')->insert($component + [
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // 2. Hitungan komponen tanpa kolom lama, mis. {"izin": 1, "mangkir": 0}. Hanya dijaga hasColumn: saat
        // `migrate --pretend` introspeksi schema selalu kosong, sehingga ALTER ini tetap tampil di pratinjau.
        if (! Schema::hasColumn('attendances', 'component_counts')) {
            Schema::table('attendances', function (Blueprint $table) {
                $table->json('component_counts')->nullable()->after('sick_days');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Saat `migrate:rollback --pretend` introspeksi schema selalu kosong; tampilkan DROP kolomnya di pratinjau.
        if (DB::pretending() || Schema::hasColumn('attendances', 'component_counts')) {
            Schema::table('attendances', function (Blueprint $table) {
                $table->dropColumn('component_counts');
            });
        }

        Schema::dropIfExists('attendance_components');
    }
};
