<?php

namespace App\Models;

use App\Services\ScoringRuleService;
use App\Support\Scoring\AttendanceComponentData;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MissingAttributeException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Attendance extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Kolom yang wajib ikut di-select bila skor kehadiran dihitung dari baris ini (countFor / scoringWorkDays,
     * PerformanceScoreCalculator::attendanceFor). Kolom yang tidak di-select membuat perhitungan melempar
     * MissingAttributeException, bukan diam-diam dianggap 0.
     */
    public const SCORING_COLUMNS = ['work_days', 'late_less_30', 'late_more_30', 'sick_days', 'component_counts'];

    protected $fillable = [
        'user_id',
        'periode',
        'work_days',
        'late_less_30',
        'late_more_30',
        'sick_days',
        'component_counts',
    ];

    protected function casts(): array
    {
        return [
            // Hitungan komponen kehadiran tanpa kolom lama, mis. {"izin": 1, "mangkir": 0}.
            'component_counts' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Hitungan satu komponen kehadiran; tidak ada => 0.
     *
     * - Kode komponen lama (late_less_30, late_more_30, sick_days) SELALU dibaca dari kolomnya
     *   (ikatan tetap di kode, tidak bergantung pada baris master yang bisa diubah di luar Eloquent).
     * - Komponen lain dibaca dari kolom legacy_column-nya bila ada, selain itu dari component_counts[kode].
     *
     * Definisi komponen diambil dari ScoringRuleService (dimuat sekali per request, tanpa query per baris).
     *
     * @throws MissingAttributeException bila kolom yang dibutuhkan tidak ikut di-select (lihat SCORING_COLUMNS).
     */
    public function countFor(string $code): int
    {
        $legacyColumn = AttendanceComponentData::legacyColumnFor($code)
            ?? ScoringRuleService::component($code)?->legacyColumn;

        if ($legacyColumn !== null) {
            return (int) ($this->scoringAttribute($legacyColumn) ?? 0);
        }

        $counts = $this->scoringAttribute('component_counts');

        return is_array($counts) ? (int) ($counts[$code] ?? 0) : 0;
    }

    /**
     * Hari kerja untuk perhitungan skor (null => 0).
     *
     * @throws MissingAttributeException bila work_days tidak ikut di-select.
     */
    public function scoringWorkDays(): int
    {
        return (int) ($this->scoringAttribute('work_days') ?? 0);
    }

    /**
     * Hitungan semua komponen yang dikenal (atau kode tertentu), per kode.
     *
     * @param  list<string>|null  $codes
     * @return array<string, int>
     */
    public function componentCounts(?array $codes = null): array
    {
        $codes ??= ScoringRuleService::components()->keys()->all();
        $counts = [];

        foreach ($codes as $code) {
            $counts[$code] = $this->countFor($code);
        }

        return $counts;
    }

    /**
     * Seperti getAttribute(), tetapi baris yang dimuat dari database tanpa kolom ini (select parsial) ditolak
     * agar komponen tidak hilang diam-diam dari skor. Baris yang baru dibuat memakai default kolom (null/0).
     */
    private function scoringAttribute(string $key): mixed
    {
        if ($this->exists && ! $this->wasRecentlyCreated && ! array_key_exists($key, $this->getAttributes())) {
            throw new MissingAttributeException($this, $key);
        }

        return $this->getAttribute($key);
    }
}
