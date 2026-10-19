<?php

namespace App\Models;

use App\Policies\AttendanceComponentPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Master komponen kehadiran (terlambat, sakit, izin, mangkir, cuti, ...).
 *
 * Komponen dengan legacy_column dibaca dari kolom lama tabel attendances; komponen lain dari
 * attendances.component_counts[code]. Kode dan legacy_column tidak bisa diubah setelah dibuat karena
 * menentukan dari mana data historis dibaca. Komponen yang sudah dipakai tidak bisa dihapus (nonaktifkan saja)
 * dan satuannya tetap; komponen yang masih dinilai aturan bulan lalu, berjalan, atau terjadwal tidak bisa
 * dinonaktifkan. Penjaga ini berlaku untuk semua jalur Eloquent (panel, tinker, seeder), bukan hanya panel.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $unit
 * @property string|null $description
 * @property string|null $legacy_column
 * @property int $sort_order
 * @property bool $is_active
 */
class AttendanceComponent extends Model
{
    public const UNIT_DAY = 'hari';

    public const UNIT_OCCURRENCE = 'kejadian';

    /** @var list<string> */
    public const UNITS = [self::UNIT_DAY, self::UNIT_OCCURRENCE];

    /** Kolom hitungan lama di tabel attendances. */
    public const LEGACY_COLUMNS = ['late_less_30', 'late_more_30', 'sick_days'];

    public const CODE_PATTERN = '/^[a-z][a-z0-9_]{1,49}$/D';

    protected $guarded = [
        'id',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (AttendanceComponent $component): void {
            $component->assertValid();
        });

        static::deleting(function (AttendanceComponent $component): void {
            $component->assertDeletable();
        });
    }

    /**
     * @throws ValidationException
     */
    private function assertValid(): void
    {
        if ($this->exists && $this->isDirty('code')) {
            throw ValidationException::withMessages([
                'code' => 'Kode komponen tidak dapat diubah setelah dibuat.',
            ]);
        }

        if ($this->exists && $this->isDirty('legacy_column')) {
            throw ValidationException::withMessages([
                'legacy_column' => 'Kolom lama komponen tidak dapat diubah setelah dibuat.',
            ]);
        }

        if (! is_string($this->code) || preg_match(self::CODE_PATTERN, $this->code) !== 1) {
            throw ValidationException::withMessages([
                'code' => 'Kode komponen hanya boleh huruf kecil, angka, dan garis bawah (diawali huruf, 2-50 karakter).',
            ]);
        }

        if (static::query()
            ->where('code', $this->code)
            ->when($this->exists, fn ($query) => $query->whereKeyNot($this->getKey()))
            ->exists()) {
            throw ValidationException::withMessages([
                'code' => "Kode komponen \"{$this->code}\" sudah dipakai komponen lain.",
            ]);
        }

        if (blank($this->name)) {
            throw ValidationException::withMessages([
                'name' => 'Nama komponen wajib diisi.',
            ]);
        }

        $this->unit ??= self::UNIT_DAY;

        if (! in_array($this->unit, self::UNITS, true)) {
            throw ValidationException::withMessages([
                'unit' => 'Satuan komponen harus "hari" atau "kejadian".',
            ]);
        }

        if ($this->legacy_column !== null && ! in_array($this->legacy_column, self::LEGACY_COLUMNS, true)) {
            throw ValidationException::withMessages([
                'legacy_column' => 'Kolom lama hanya boleh late_less_30, late_more_30, atau sick_days.',
            ]);
        }

        if ($this->legacy_column !== null
            && static::query()
                ->where('legacy_column', $this->legacy_column)
                ->when($this->exists, fn ($query) => $query->whereKeyNot($this->getKey()))
                ->exists()) {
            throw ValidationException::withMessages([
                'legacy_column' => 'Kolom lama ini sudah dipakai komponen lain.',
            ]);
        }

        // Satuan menentukan arti penalti di versi aturan (poin/hari atau poin/kejadian).
        if ($this->exists && $this->isDirty('unit') && $this->usageReason() !== null) {
            throw ValidationException::withMessages([
                'unit' => 'Satuan komponen yang sudah dipakai tidak dapat diubah karena mengubah arti aturan penilaian yang memakainya.',
            ]);
        }

        if ($this->exists && $this->isDirty('is_active') && ! $this->is_active) {
            $rules = AttendanceComponentPolicy::rulesStillScoring((string) $this->getOriginal('code'));

            if ($rules !== []) {
                throw ValidationException::withMessages([
                    'is_active' => 'Komponen masih dinilai oleh '.implode(', ', $rules).'. Buat versi aturan baru tanpa penalti untuk komponen ini terlebih dahulu.',
                ]);
            }
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertDeletable(): void
    {
        $reason = $this->usageReason();

        if ($reason !== null) {
            throw ValidationException::withMessages([
                'code' => $reason.' Nonaktifkan saja.',
            ]);
        }
    }

    /**
     * Alasan komponen dianggap sudah dipakai (bawaan, dipakai versi aturan, atau dipakai data kehadiran);
     * null bila belum pernah dipakai.
     */
    private function usageReason(): ?string
    {
        $code = (string) $this->getOriginal('code');

        if ($this->getOriginal('legacy_column') !== null) {
            return 'Komponen bawaan kolom kehadiran tidak dapat dihapus.';
        }

        $usedByRule = ScoringRule::query()
            ->pluck('attendance_rules')
            ->contains(static fn ($entries): bool => is_array($entries)
                && collect($entries)->contains(static fn ($entry): bool => is_array($entry) && ($entry['component'] ?? null) === $code));

        if ($usedByRule) {
            return 'Komponen sudah dipakai aturan penilaian sehingga tidak dapat dihapus.';
        }

        // Kode tervalidasi pola [a-z0-9_], aman dipakai sebagai path JSON.
        $usedByAttendance = preg_match(self::CODE_PATTERN, $code) === 1
            && Attendance::withTrashed()->whereNotNull("component_counts->{$code}")->exists();

        return $usedByAttendance
            ? 'Komponen sudah dipakai data kehadiran sehingga tidak dapat dihapus.'
            : null;
    }
}
