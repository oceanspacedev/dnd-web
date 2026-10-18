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
 *
 * Komponen yang dinilai aturan periode data tetapi tidak dikirim tidak ditolak (klien lama tetap jalan), tetapi
 * disebut di 'warnings' respons store/update (countWarnings()).
 *
 * Respons GET memakai kunci yang sama dengan bentuk lain (components.{kode} = {name, unit, count}). Agar body GET
 * bisa dikirim balik utuh ke POST/PUT (seperti kunci respons lain: metrics, user, scoring_rule), entri berbentuk
 * objek diabaikan dan hanya entri angka yang dipakai; entri objek yang count-nya berbeda dengan hasil simpan
 * disebut di 'warnings' (kirim jumlahnya sebagai angka).
 */
trait ValidatesAttendanceCounts
{
    /** Field hitungan lama yang tetap diterima di luar 'components'. */
    private const LEGACY_COUNT_FIELDS = ['late_less_30', 'late_more_30', 'sick_days'];

    /**
     * Entri 'components' berbentuk objek respons GET ({name, unit, count}) yang diabaikan prepareForValidation().
     *
     * @var array<array-key, array<mixed>>
     */
    private array $echoedComponents = [];

    protected function prepareForValidation(): void
    {
        $periode = $this->input('periode');

        if (is_string($periode) && ($normalized = Periode::normalize($periode)) !== null) {
            $this->merge(['periode' => $normalized]);
        }

        $components = $this->input('components');

        // Hanya PUT/PATCH (kirim balik respons GET data yang sama). POST tidak punya hitungan tersimpan untuk
        // menggantikan entri yang diabaikan, jadi entri berbentuk objek tetap ditolak validasi (422).
        if (! $this->isMethod('POST') && is_array($components) && ! array_is_list($components)) {
            $this->echoedComponents = array_filter(
                $components,
                static fn (mixed $value): bool => is_array($value) && array_key_exists('count', $value),
            );
            $this->merge(['components' => array_diff_key($components, $this->echoedComponents)]);
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
                if ($this->keepsStoredPeriode($value)) {
                    return;
                }

                if (($error = AttendanceCountInput::periodeError($value)) !== null) {
                    $fail($error);
                }
            },
        ];
    }

    /**
     * Periode yang dikirim sama dengan periode tersimpan data yang diubah (UpdateAttendanceRequest), sehingga
     * tidak diperiksa ulang formatnya: mengirim balik periode lama (mis. data sampah 1900-01) sama dengan tidak
     * mengirimnya. Store tidak punya data tersimpan.
     */
    protected function keepsStoredPeriode(mixed $value): bool
    {
        return false;
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
        $messages = [];

        foreach (self::LEGACY_COUNT_FIELDS as $field) {
            $messages[$field.'.integer'] = "{$field} harus bilangan bulat.";
            $messages[$field.'.min'] = "{$field} minimal 0.";
            $messages[$field.'.max'] = "{$field} maksimal ".AttendanceCountInput::MAX_COUNT.'.';
        }

        return $messages + [
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
     * Peringatan respons store/update (kunci 'warnings'; data tetap disimpan): entri 'components' berbentuk objek
     * yang diabaikan (ignoredComponentWarnings()) dan komponen yang dinilai tetapi tidak dikirim
     * (missingComponentWarnings()).
     *
     * @return list<string>
     */
    public function countWarnings(Attendance $attendance): array
    {
        return [...$this->ignoredComponentWarnings($attendance), ...$this->missingComponentWarnings($attendance)];
    }

    /**
     * Entri 'components' berbentuk objek (salinan respons GET) tidak dipakai. Bila count-nya berbeda dengan hitungan
     * tersimpan, klien kemungkinan mengubahnya di salinan respons, jadi disebut agar nilainya tidak hilang
     * diam-diam. Komponen lama yang field-nya ikut dikirim (late_less_30, late_more_30, sick_days) tidak disebut:
     * field itulah yang dipakai.
     *
     * @return list<string>
     */
    private function ignoredComponentWarnings(Attendance $attendance): array
    {
        $warnings = [];

        foreach ($this->echoedComponents as $code => $component) {
            $code = (string) $code;
            $count = $component['count'];
            $stored = $attendance->countFor($code);

            if ((in_array($code, self::LEGACY_COUNT_FIELDS, true) && $this->has($code))
                || ! is_numeric($count)
                || (int) $count === $stored) {
                continue;
            }

            $warnings[] = "components.{$code} dikirim sebagai objek (bentuk respons GET) sehingga diabaikan; tersimpan {$stored}. "
                .'Kirim jumlahnya sebagai angka: "components": {"'.$code.'": '.(int) $count.'}.';
        }

        return $warnings;
    }

    /**
     * Peringatan untuk klien yang hanya mengirim field lama: komponen yang dinilai versi aturan periode data
     * (penalti > 0 atau mengurangi hari kerja) dan disimpan di component_counts, tetapi tidak dikirim di
     * 'components' dan hitungannya 0 setelah disimpan (mis. izin dan mangkir sejak November 2026, saat sick_days
     * tidak lagi berisi izin). Sama dengan peringatan kolom yang tidak ada di file import.
     *
     * @return list<string>
     */
    private function missingComponentWarnings(Attendance $attendance): array
    {
        $periode = AttendanceCountInput::periode($attendance->periode);

        if ($periode === null || ! $this->checksMissingComponents()) {
            return [];
        }

        $rule = ScoringRuleService::forPeriode($periode);
        $sent = $this->input('components');
        $sent = is_array($sent) ? $sent : [];
        $warnings = [];

        foreach ($rule->componentCodes() as $code) {
            if (AttendanceCountInput::storageColumn($code) !== null
                || array_key_exists($code, $sent)
                || ($rule->penaltyPoints($code) <= 0 && ! $rule->reducesBase($code))
                || $attendance->countFor($code) > 0) {
                continue;
            }

            $component = ScoringRuleService::component($code);
            $warning = "components.{$code} tidak dikirim sehingga ".AttendanceCountInput::componentName($code)
                .' dihitung 0 untuk periode '.Periode::label($periode)
                .($component !== null ? ' ('.AttendanceCountInput::ruleSummary($component, $rule).')' : '').'.';

            if ($code === AttendanceCountInput::IZIN_CODE) {
                $warning .= ' Pada aturan periode ini sick_days hanya berisi sakit: kirim jumlah izin di components.'.AttendanceCountInput::IZIN_CODE.'.';
            }

            $warnings[] = $warning;
        }

        return $warnings;
    }

    /**
     * Store selalu memeriksa komponen yang tidak dikirim; UpdateAttendanceRequest hanya bila hitungan atau periode
     * ikut dikirim.
     */
    protected function checksMissingComponents(): bool
    {
        return true;
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
