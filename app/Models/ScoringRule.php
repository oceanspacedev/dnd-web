<?php

namespace App\Models;

use App\Services\PerformanceScoreCalculator;
use App\Support\Periode;
use App\Support\Scoring\ScoringRuleData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/**
 * Satu versi aturan penilaian kinerja (bobot KPI/Kehadiran/Penilaian, poin maksimal penilaian,
 * dan parameter tiap komponen kehadiran) yang berlaku mulai effective_from ('Y-m').
 *
 * Versi baru hanya boleh berlaku mulai bulan berjalan atau sesudahnya. Versi yang sudah dipakai bulan yang
 * telah berakhir (effective_from asli < bulan berjalan) terkunci: hanya nama dan catatan yang boleh diubah,
 * dan tidak bisa dihapus. Selama bulan pertamanya masih berjalan, versi masih bisa dikoreksi atau dihapus
 * (data kehadiran bulan itu baru diinput setelah bulannya selesai). Perubahan aturan untuk bulan yang sudah
 * lewat dilakukan dengan membuat versi baru.
 * Resolusi versi per periode selalu lewat ScoringRuleService::forPeriode() (memo + cache + fallback).
 *
 * Penjaga ini berjalan di event Eloquent; penulisan lewat query builder, saveQuietly, atau SQL langsung
 * tidak dijaga (lihat ScoringRuleService).
 *
 * @property int $id
 * @property string $name
 * @property string $effective_from
 * @property string $engine
 * @property float $kpi_weight
 * @property float $attendance_weight
 * @property float $review_weight
 * @property int $review_max_points
 * @property array<int, array{component: string, reduces_base: bool, penalty_points: int|float}> $attendance_rules
 * @property string|null $notes
 * @property int|null $created_by
 */
class ScoringRule extends Model
{
    /** Kolom yang masih boleh diubah pada versi yang terkunci. */
    public const EDITABLE_WHEN_LOCKED = ['name', 'notes', 'updated_at'];

    /** Poin penilaian tertinggi yang bisa dicapai: 4 aspek x skala 1-5. */
    public const MIN_REVIEW_MAX_POINTS = 20;

    protected $guarded = [
        'id',
    ];

    protected function casts(): array
    {
        return [
            'kpi_weight' => 'float',
            'attendance_weight' => 'float',
            'review_weight' => 'float',
            'review_max_points' => 'integer',
            'attendance_rules' => 'array',
            'created_by' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ScoringRule $rule): void {
            $rule->assertValid();
        });

        static::deleting(function (ScoringRule $rule): void {
            if ($rule->isLocked()) {
                throw ValidationException::withMessages([
                    'effective_from' => 'Versi aturan yang sudah berlaku tidak dapat dihapus. Buat versi baru untuk mengubah aturan.',
                ]);
            }
        });
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Terkunci bila versi ini (menurut effective_from yang tersimpan) sudah dipakai bulan yang telah berakhir,
     * yaitu effective_from < bulan berjalan. Versi bulan berjalan masih bisa dikoreksi sampai bulannya selesai.
     */
    public function isLocked(): bool
    {
        if (! $this->exists) {
            return false;
        }

        $original = (string) ($this->getOriginal('effective_from') ?? $this->effective_from);

        return Periode::isValid($original) && strcmp($original, Periode::current()) < 0;
    }

    /**
     * @throws ValidationException
     */
    private function assertValid(): void
    {
        $effectiveFrom = (string) $this->effective_from;

        if (! Periode::isValid($effectiveFrom)) {
            throw ValidationException::withMessages([
                'effective_from' => 'Periode berlaku harus berformat YYYY-MM.',
            ]);
        }

        if ($this->isLocked()) {
            $this->keepEquivalentAttendanceRules();
            $changed = array_values(array_diff(array_keys($this->getDirty()), self::EDITABLE_WHEN_LOCKED));

            if ($changed !== []) {
                throw ValidationException::withMessages(array_fill_keys(
                    $changed,
                    'Versi aturan yang sudah berlaku hanya boleh diubah nama dan catatannya. Buat versi baru untuk mengubah aturan.',
                ));
            }

            return;
        }

        if (strcmp($effectiveFrom, Periode::current()) < 0) {
            throw ValidationException::withMessages([
                'effective_from' => 'Versi aturan hanya boleh berlaku mulai bulan berjalan ('.Periode::current().') atau sesudahnya.',
            ]);
        }

        if (static::query()
            ->where('effective_from', $effectiveFrom)
            ->when($this->exists, fn ($query) => $query->whereKeyNot($this->getKey()))
            ->exists()) {
            throw ValidationException::withMessages([
                'effective_from' => "Sudah ada versi aturan yang berlaku mulai {$effectiveFrom}.",
            ]);
        }

        if (blank($this->name)) {
            throw ValidationException::withMessages([
                'name' => 'Nama versi aturan wajib diisi.',
            ]);
        }

        // Samakan dengan default kolom agar model yang baru disimpan langsung lengkap.
        $this->engine ??= PerformanceScoreCalculator::ENGINE_STANDARD;
        $this->review_max_points ??= 20;
        $engine = (string) $this->engine;

        if (! PerformanceScoreCalculator::supportsEngine($engine)) {
            throw ValidationException::withMessages([
                'engine' => "Mesin perhitungan '{$engine}' tidak dikenal.",
            ]);
        }

        $this->assertWeights();
        $this->assertReviewMaxPoints();
        $this->assertAttendanceRules();
    }

    /**
     * Validasi nilai mentah (bukan hasil cast float, yang mengubah teks bukan angka menjadi 0). Kolom
     * decimal(6,2) membulatkan ke 2 desimal, jadi bobot dengan lebih dari 2 desimal ditolak agar total
     * yang tersimpan tetap tepat 100.
     */
    private function assertWeights(): void
    {
        $weights = [];

        foreach (['kpi_weight', 'attendance_weight', 'review_weight'] as $attribute) {
            $raw = $this->getAttributes()[$attribute] ?? null;

            if (! is_numeric($raw) || ! is_finite((float) $raw) || (float) $raw < 0) {
                throw ValidationException::withMessages([
                    $attribute => 'Bobot harus berupa angka dan tidak boleh negatif.',
                ]);
            }

            $weight = (float) $raw;

            if (abs(round($weight, 2) - $weight) > 1e-9) {
                throw ValidationException::withMessages([
                    $attribute => 'Bobot maksimal 2 angka di belakang koma.',
                ]);
            }

            $weights[$attribute] = round($weight, 2);
        }

        if (abs(array_sum($weights) - 100) > 0.001) {
            throw ValidationException::withMessages([
                'kpi_weight' => 'Total bobot KPI, Kehadiran, dan Penilaian harus 100.',
            ]);
        }

        foreach ($weights as $attribute => $weight) {
            $this->setAttribute($attribute, $weight);
        }
    }

    /**
     * Validasi nilai mentah: cast 'integer' membuat 20.5 terbaca 20 padahal MySQL menyimpan 21.
     * Batas atas mengikuti kolom unsignedSmallInteger.
     */
    private function assertReviewMaxPoints(): void
    {
        $raw = $this->getAttributes()['review_max_points'] ?? null;
        $maxPoints = is_bool($raw)
            ? false
            : filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => self::MIN_REVIEW_MAX_POINTS, 'max_range' => 65535]]);

        // Penilaian berisi 4 aspek x skala 1-5 = 20 poin; batas lebih kecil membuat skor melebihi bobotnya.
        if ($maxPoints === false) {
            throw ValidationException::withMessages([
                'review_max_points' => 'Poin maksimal penilaian harus bilangan bulat antara '.self::MIN_REVIEW_MAX_POINTS.' dan 65535 (4 aspek × skala 1–5).',
            ]);
        }

        $this->setAttribute('review_max_points', $maxPoints);
    }

    private function assertAttendanceRules(): void
    {
        $entries = $this->attendance_rules;

        if (! is_array($entries) || ! array_is_list($entries)) {
            throw ValidationException::withMessages([
                'attendance_rules' => 'Aturan kehadiran harus berupa daftar komponen.',
            ]);
        }

        $knownCodes = array_flip(AttendanceComponent::query()->pluck('code')->all());
        $seen = [];
        $normalized = [];

        foreach ($entries as $entry) {
            $code = is_array($entry) ? ($entry['component'] ?? null) : null;

            if (! is_string($code) || ! isset($knownCodes[$code])) {
                throw ValidationException::withMessages([
                    'attendance_rules' => 'Komponen kehadiran "'.(is_scalar($code) ? $code : '?').'" tidak ditemukan.',
                ]);
            }

            if (isset($seen[$code])) {
                throw ValidationException::withMessages([
                    'attendance_rules' => "Komponen kehadiran \"{$code}\" tercantum lebih dari sekali.",
                ]);
            }

            $seen[$code] = true;
            $penalty = $entry['penalty_points'] ?? null;

            if (! is_numeric($penalty) || ! is_finite((float) $penalty) || (float) $penalty < 0) {
                throw ValidationException::withMessages([
                    'attendance_rules' => "Poin penalti komponen \"{$code}\" harus berupa angka dan tidak boleh negatif.",
                ]);
            }

            $reducesBase = $entry['reduces_base'] ?? null;

            if (! is_bool($reducesBase) && ! in_array($reducesBase, [0, 1, '0', '1'], true)) {
                throw ValidationException::withMessages([
                    'attendance_rules' => "Isian \"mengurangi hari kerja\" komponen \"{$code}\" harus ya/tidak.",
                ]);
            }

            $normalized[] = [
                'component' => $code,
                'reduces_base' => (bool) $reducesBase,
                'penalty_points' => $penalty + 0,
            ];
        }

        $this->attendance_rules = $normalized;
    }

    /**
     * Pada versi terkunci, attendance_rules yang isinya sama tetapi tipenya berbeda (mis. penalti '5' dari
     * form, 1.0 dari ScoringRuleData, reduces_base '1') bukan perubahan: nilai tersimpan dipertahankan apa
     * adanya. Urutan komponen tetap dihitung sebagai perubahan.
     */
    private function keepEquivalentAttendanceRules(): void
    {
        if (! $this->isDirty('attendance_rules')) {
            return;
        }

        $original = ScoringRuleData::normalizeAttendanceRules($this->getOriginal('attendance_rules'));
        $incoming = ScoringRuleData::normalizeAttendanceRules($this->attendance_rules);

        if ($original === $incoming && $this->attendanceRulesEntryCount() === count($original)) {
            $this->attributes['attendance_rules'] = $this->getRawOriginal('attendance_rules');
        }
    }

    private function attendanceRulesEntryCount(): int
    {
        $entries = $this->attendance_rules;

        return is_array($entries) ? count($entries) : -1;
    }
}
