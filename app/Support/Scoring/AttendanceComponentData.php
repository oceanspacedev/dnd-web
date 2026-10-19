<?php

namespace App\Support\Scoring;

use App\Models\AttendanceComponent;

/**
 * Definisi komponen kehadiran (attendance_components) yang dibaca sekali per request lewat ScoringRuleService.
 */
final readonly class AttendanceComponentData
{
    /**
     * Kode komponen lama => kolomnya di tabel attendances. Ikatan ini tetap di kode: data historis
     * selalu dibaca dari kolom ini walaupun baris master komponennya diubah di luar Eloquent.
     */
    public const LEGACY_COLUMN_BY_CODE = [
        'late_less_30' => 'late_less_30',
        'late_more_30' => 'late_more_30',
        'sick_days' => 'sick_days',
    ];

    public function __construct(
        public string $code,
        public string $name,
        public string $unit,
        public ?string $description,
        public ?string $legacyColumn,
        public int $sortOrder,
        public bool $isActive,
    ) {}

    /**
     * Tiga komponen yang sudah punya kolom di tabel attendances, identik dengan seed migration
     * 2026_10_18_090000. Dipakai bila tabel komponen belum ada/kosong.
     *
     * @return array<string, self>
     */
    public static function legacyDefaults(): array
    {
        return [
            'late_less_30' => new self('late_less_30', 'Terlambat ≤ 30 menit', 'kejadian', 'Sejak November 2026 hanya keterlambatan lebih dari 5 menit yang dihitung.', self::LEGACY_COLUMN_BY_CODE['late_less_30'], 1, true),
            'late_more_30' => new self('late_more_30', 'Terlambat > 30 menit', 'kejadian', null, self::LEGACY_COLUMN_BY_CODE['late_more_30'], 2, true),
            'sick_days' => new self('sick_days', 'Sakit', 'hari', 'Sebelum November 2026 kolom ini berisi sakit dan izin (Sakit/Izin).', self::LEGACY_COLUMN_BY_CODE['sick_days'], 3, true),
        ];
    }

    /**
     * Kolom attendances untuk kode komponen lama (late_less_30, late_more_30, sick_days); null untuk komponen lain.
     */
    public static function legacyColumnFor(string $code): ?string
    {
        return self::LEGACY_COLUMN_BY_CODE[$code] ?? null;
    }

    public static function fromModel(AttendanceComponent $component): self
    {
        return new self(
            code: (string) $component->code,
            name: (string) $component->name,
            unit: (string) $component->unit,
            description: $component->description !== null ? (string) $component->description : null,
            legacyColumn: $component->legacy_column !== null ? (string) $component->legacy_column : null,
            sortOrder: (int) $component->sort_order,
            isActive: (bool) $component->is_active,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        return new self(
            code: (string) $attributes['code'],
            name: (string) $attributes['name'],
            unit: (string) $attributes['unit'],
            description: isset($attributes['description']) ? (string) $attributes['description'] : null,
            legacyColumn: isset($attributes['legacy_column']) ? (string) $attributes['legacy_column'] : null,
            sortOrder: (int) ($attributes['sort_order'] ?? 0),
            isActive: (bool) ($attributes['is_active'] ?? true),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'unit' => $this->unit,
            'description' => $this->description,
            'legacy_column' => $this->legacyColumn,
            'sort_order' => $this->sortOrder,
            'is_active' => $this->isActive,
        ];
    }

    public function usesLegacyColumn(): bool
    {
        return $this->legacyColumn !== null;
    }
}
