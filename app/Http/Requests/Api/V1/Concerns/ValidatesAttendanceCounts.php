<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Models\Attendance;
use App\Services\ScoringRuleService;
use App\Support\Periode;
use App\Support\Scoring\AttendanceCountInput;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Input hitungan kehadiran API: field lama (late_less_30, late_more_30, sick_days) dan objek opsional
 * 'components' {kode: bilangan bulat >= 0} untuk kode komponen yang ada di master attendance_components.
 * Kode komponen lama di 'components' disimpan ke kolomnya, kode lain ke component_counts.
 *
 * Periode dinormalisasi ke 'Y-m' (Periode::normalize) sebelum validasi; yang tidak terbaca atau di luar
 * tahun 2000-2100 ditolak (422).
 *
 * Komponen yang tidak dipakai versi aturan periode data (AttendanceCountInput::isUsedBy(), mis. izin sebelum
 * November 2026 saat izin masih dicatat di sick_days) hanya boleh dikirim 0: nilai > 0 ditolak (422) pada kunci
 * components.{kode}, dengan langkah perbaikannya (izin: tambahkan ke sick_days). Field lama (late_less_30,
 * late_more_30, sick_days) selalu diterima. Mengganti periode data yang masih menyimpan hitungan seperti itu
 * ditolak di UpdateAttendanceRequest.
 */
trait ValidatesAttendanceCounts
{
    /** Field hitungan lama yang tetap diterima di luar 'components'. */
    private const LEGACY_COUNT_FIELDS = ['late_less_30', 'late_more_30', 'sick_days'];

    protected function prepareForValidation(): void
    {
        $periode = $this->input('periode');

        if (is_string($periode) && ($normalized = Periode::normalize($periode)) !== null) {
            $this->merge(['periode' => $normalized]);
        }
    }

    /**
     * @return list<mixed>
     */
    protected function periodeRules(): array
    {
        return [
            'string',
            'max:50',
            function (string $attribute, mixed $value, Closure $fail): void {
                if (($error = AttendanceCountInput::periodeError($value)) !== null) {
                    $fail($error);
                }
            },
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function countRules(): array
    {
        $rules = [];

        foreach (self::LEGACY_COUNT_FIELDS as $field) {
            // null diterima seperti sebelumnya dan disimpan sebagai 0 (kolom NOT NULL). Batas atas mencegah
            // angka sampah meluap di kolom INT (500) dan disamakan dengan import.
            $rules[$field] = ['nullable', 'integer', 'min:0', 'max:'.AttendanceCountInput::MAX_COUNT];
        }

        $rules['components'] = [
            'sometimes',
            'array',
            function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_array($value)) {
                    return;
                }

                $unknown = [];

                foreach ($value as $code => $count) {
                    if (! is_string($code) || ScoringRuleService::component($code) === null) {
                        $unknown[] = (string) $code;

                        continue;
                    }

                    if (in_array($code, self::LEGACY_COUNT_FIELDS, true)
                        && $this->filled($code)
                        && is_numeric($count)
                        && (int) $this->input($code) !== (int) $count) {
                        $fail("Nilai components.{$code} berbeda dengan field {$code}.");
                    }
                }

                if ($unknown !== []) {
                    $fail('Kode komponen kehadiran tidak dikenal: '.implode(', ', $unknown).'.');
                }
            },
        ];
        $rules['components.*'] = [
            'integer',
            'min:0',
            'max:'.AttendanceCountInput::MAX_COUNT,
            function (string $attribute, mixed $value, Closure $fail): void {
                $code = Str::after($attribute, 'components.');
                $periode = $this->attendancePeriode();

                // Kode tidak dikenal ditolak aturan 'components'; periode tidak valid ditolak aturan 'periode'.
                if ($periode === null
                    || AttendanceCountInput::unusedCodes([$code => $value], ScoringRuleService::forPeriode($periode)) === []) {
                    return;
                }

                $fail(AttendanceCountInput::unusedComponentsMessage([$code], $periode, AttendanceCountInput::CONTEXT_API));
            },
        ];

        return $rules;
    }

    /**
     * Periode data yang dinilai ('Y-m'), untuk menentukan komponen yang dipakai versi aturannya; null bila belum
     * valid. Store memakai periode yang dikirim; UpdateAttendanceRequest juga jatuh ke periode data yang diubah.
     */
    protected function attendancePeriode(): ?string
    {
        return AttendanceCountInput::periode($this->input('periode'));
    }

    /**
     * Hari kerja: bilangan bulat 0..MAX_WORK_DAYS (0 tetap diterima seperti sebelumnya = skor kehadiran 0).
     *
     * @return list<string>
     */
    protected function workDayRules(): array
    {
        return ['integer', 'min:0', 'max:'.AttendanceCountInput::MAX_WORK_DAYS];
    }

    /**
     * @return array<string, string>
     */
    protected function countMessages(): array
    {
        return [
            'components.array' => 'components harus berupa objek {kode_komponen: jumlah}.',
            'components.*.integer' => 'Jumlah komponen kehadiran harus bilangan bulat.',
            'components.*.min' => 'Jumlah komponen kehadiran minimal 0.',
            'components.*.max' => 'Jumlah komponen kehadiran maksimal '.AttendanceCountInput::MAX_COUNT.'.',
            'work_days.max' => 'Jumlah hari kerja maksimal '.AttendanceCountInput::MAX_WORK_DAYS.'.',
        ];
    }

    /**
     * Hitungan per kode komponen dari field lama dan 'components' (setelah validasi). null = 0.
     *
     * @return array<string, int>
     */
    public function componentCounts(): array
    {
        $validated = $this->validated();
        $counts = [];

        foreach (self::LEGACY_COUNT_FIELDS as $field) {
            if (array_key_exists($field, $validated)) {
                $counts[$field] = (int) ($validated[$field] ?? 0);
            }
        }

        foreach ((array) ($validated['components'] ?? []) as $code => $count) {
            $counts[(string) $code] = (int) $count;
        }

        return $counts;
    }

    /**
     * Atribut Attendance siap disimpan. Pada update, component_counts digabung dengan nilai yang sudah ada
     * dan hanya disentuh bila ada komponen non-kolom yang dikirim.
     *
     * @return array<string, mixed>
     */
    public function attendanceAttributes(?Attendance $current = null): array
    {
        $attributes = Arr::except($this->validated(), ['components', ...self::LEGACY_COUNT_FIELDS]);

        return $attributes + AttendanceCountInput::attributes($this->componentCounts(), $current?->component_counts);
    }
}
