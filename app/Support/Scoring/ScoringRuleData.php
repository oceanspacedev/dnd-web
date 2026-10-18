<?php

namespace App\Support\Scoring;

use App\Models\ScoringRule;

/**
 * Satu versi aturan penilaian kinerja yang sudah di-resolve untuk sebuah periode.
 *
 * attendanceRules dikunci per kode komponen kehadiran (attendance_components.code):
 * - reduces_base   => hitungannya mengurangi hari kerja pada rumus dasar;
 * - penalty_points => poin penalti per hari/kejadian.
 */
final readonly class ScoringRuleData
{
    /**
     * @param  array<string, array{reduces_base: bool, penalty_points: float}>  $attendanceRules
     */
    public function __construct(
        public ?int $id,
        public string $name,
        public string $effectiveFrom,
        public string $engine,
        public float $kpiWeight,
        public float $attendanceWeight,
        public float $reviewWeight,
        public int $reviewMaxPoints,
        public array $attendanceRules,
    ) {}

    /**
     * Aturan lama tertanam di kode, identik dengan seed versi pertama (migration 2026_10_18_090100).
     * Dipakai bila tabel aturan belum ada/kosong (mis. skema tes yang dibangun manual).
     */
    public static function legacy(): self
    {
        return new self(
            id: null,
            name: 'Aturan Lama',
            effectiveFrom: '2000-01',
            engine: 'standard',
            kpiWeight: 70.0,
            attendanceWeight: 15.0,
            reviewWeight: 15.0,
            reviewMaxPoints: 20,
            attendanceRules: [
                'late_less_30' => ['reduces_base' => true, 'penalty_points' => 1.0],
                'late_more_30' => ['reduces_base' => true, 'penalty_points' => 3.0],
                'sick_days' => ['reduces_base' => true, 'penalty_points' => 5.0],
            ],
        );
    }

    public static function fromModel(ScoringRule $rule): self
    {
        return new self(
            id: (int) $rule->getKey(),
            name: (string) $rule->name,
            effectiveFrom: (string) $rule->effective_from,
            engine: (string) $rule->engine,
            kpiWeight: (float) $rule->kpi_weight,
            attendanceWeight: (float) $rule->attendance_weight,
            reviewWeight: (float) $rule->review_weight,
            reviewMaxPoints: (int) $rule->review_max_points,
            attendanceRules: self::normalizeAttendanceRules($rule->attendance_rules),
        );
    }

    /**
     * Kebalikan dari toArray() (bentuk yang disimpan di cache aplikasi).
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        return new self(
            id: isset($attributes['id']) ? (int) $attributes['id'] : null,
            name: (string) $attributes['name'],
            effectiveFrom: (string) $attributes['effective_from'],
            engine: (string) $attributes['engine'],
            kpiWeight: (float) $attributes['kpi_weight'],
            attendanceWeight: (float) $attributes['attendance_weight'],
            reviewWeight: (float) $attributes['review_weight'],
            reviewMaxPoints: (int) $attributes['review_max_points'],
            attendanceRules: self::normalizeAttendanceRules($attributes['attendance_rules'] ?? []),
        );
    }

    /**
     * Ubah bentuk tersimpan (list {component, reduces_base, penalty_points}) atau yang sudah dikunci per kode
     * menjadi array per kode komponen.
     *
     * @return array<string, array{reduces_base: bool, penalty_points: float}>
     */
    public static function normalizeAttendanceRules(mixed $rules): array
    {
        if (! is_array($rules)) {
            return [];
        }

        $normalized = [];

        foreach ($rules as $key => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $code = $entry['component'] ?? (is_string($key) ? $key : null);

            if (! is_string($code) || $code === '') {
                continue;
            }

            $normalized[$code] = [
                'reduces_base' => (bool) ($entry['reduces_base'] ?? false),
                'penalty_points' => (float) ($entry['penalty_points'] ?? 0),
            ];
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'effective_from' => $this->effectiveFrom,
            'engine' => $this->engine,
            'kpi_weight' => $this->kpiWeight,
            'attendance_weight' => $this->attendanceWeight,
            'review_weight' => $this->reviewWeight,
            'review_max_points' => $this->reviewMaxPoints,
            'attendance_rules' => $this->attendanceRulesList(),
        ];
    }

    /**
     * Bentuk tersimpan scoring_rules.attendance_rules.
     *
     * @return list<array{component: string, reduces_base: bool, penalty_points: float}>
     */
    public function attendanceRulesList(): array
    {
        $list = [];

        foreach ($this->attendanceRules as $code => $rule) {
            $list[] = ['component' => $code] + $rule;
        }

        return $list;
    }

    /**
     * @return list<string>
     */
    public function componentCodes(): array
    {
        return array_keys($this->attendanceRules);
    }

    public function hasComponent(string $code): bool
    {
        return isset($this->attendanceRules[$code]);
    }

    public function reducesBase(string $code): bool
    {
        return $this->attendanceRules[$code]['reduces_base'] ?? false;
    }

    public function penaltyPoints(string $code): float
    {
        return $this->attendanceRules[$code]['penalty_points'] ?? 0.0;
    }

    public function totalWeight(): float
    {
        return $this->kpiWeight + $this->attendanceWeight + $this->reviewWeight;
    }

    /**
     * True bila ini aturan lama tertanam di kode (tabel aturan belum tersedia), bukan baris database.
     */
    public function isFallback(): bool
    {
        return $this->id === null;
    }
}
