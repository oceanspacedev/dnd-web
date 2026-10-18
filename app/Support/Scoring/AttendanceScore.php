<?php

namespace App\Support\Scoring;

/**
 * Hasil perhitungan kehadiran menurut satu versi aturan.
 *
 * - basePct       : (hari kerja - Σ komponen pengurang) / hari kerja * 100, TIDAK dijepit (bisa negatif);
 *                   0 bila hari kerja <= 0.
 * - penaltyPoints : Σ hitungan * poin penalti (tetap dihitung walau hari kerja <= 0).
 * - finalPct      : max(0, basePct - penaltyPoints); 0 bila hari kerja <= 0.
 * - score         : finalPct / 100 * bobot kehadiran.
 */
final readonly class AttendanceScore
{
    /**
     * @param  array<string, array{count: int|float, reduces_base: bool, penalty_points: float, penalty: float}>  $components
     */
    public function __construct(
        public int|float $workDays,
        public bool $hasWorkDays,
        public float $basePct,
        public float $penaltyPoints,
        public float $finalPct,
        public float $score,
        public float $weight,
        public array $components,
    ) {}

    /**
     * Persentase dasar yang dijepit ke 0 (bentuk yang ditampilkan API presensi & evaluasi).
     * finalPct tidak berubah: max(0, max(0, base) - penalti) = max(0, base - penalti) karena penalti >= 0.
     * Penalti >= 0 dijamin karena hitungan komponen tidak boleh negatif di semua jalur input (form, import,
     * API); data produksi per Oktober 2026 juga tidak punya hitungan negatif.
     */
    public function clampedBasePct(): float
    {
        return max(0.0, $this->basePct);
    }

    /**
     * Penalti untuk ditampilkan (API, export): dibulatkan 2 desimal seperti metrik lain, agar penalti pecahan tidak
     * membawa sisa floating point (0.1 + 0.2 = 0.30000000000000004), dan int bila bilangan bulat (bentuk JSON lama,
     * mis. 10 bukan 10.0). finalPct dan score tetap dihitung dari penalti yang belum dibulatkan.
     */
    public function penaltyPointsNumber(): int|float
    {
        $penalty = round($this->penaltyPoints, 2);

        return floor($penalty) === $penalty && abs($penalty) < PHP_INT_MAX
            ? (int) $penalty
            : $penalty;
    }
}
