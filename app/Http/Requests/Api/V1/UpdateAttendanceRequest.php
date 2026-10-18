<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ValidatesAttendanceCounts;
use App\Models\Attendance;
use App\Services\ScoringRuleService;
use App\Support\Scoring\AttendanceCountInput;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateAttendanceRequest extends FormRequest
{
    use ValidatesAttendanceCounts;

    /** Data yang diubah bila user boleh mengubahnya (storedAttendance()); false = belum dibaca. */
    private Attendance|false|null $storedAttendance = false;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Dinormalisasi ke 'Y-m' sebelum validasi (prepareForValidation).
            'periode' => ['sometimes', 'required', ...$this->periodeRules(), $this->periodeChangeRule()],
            'work_days' => ['sometimes', 'required', ...$this->workDayRules()],
            // late_less_30 / late_more_30 / sick_days dan components {kode: jumlah}.
            ...$this->countRules(),
        ];
    }

    public function messages(): array
    {
        return $this->countMessages();
    }

    /**
     * Periode yang dikirim, atau periode data yang diubah bila tidak dikirim. Data yang tidak boleh diubah user
     * tidak dibaca di sini (controller menolaknya dengan 403/404), agar validasi tidak membocorkan periodenya.
     */
    protected function attendancePeriode(): ?string
    {
        if ($this->has('periode')) {
            return AttendanceCountInput::periode($this->input('periode'));
        }

        $attendance = $this->storedAttendance();

        return $attendance === null ? null : AttendanceCountInput::periode($attendance->periode);
    }

    /**
     * Periode data diganti: hitungan tersimpan yang tidak ikut dikirim di 'components' dan tidak dipakai versi
     * aturan periode baru ditolak (422 pada 'periode'), agar penaltinya tidak hilang diam-diam (mis. izin 3 hari
     * dipindah dari November ke Oktober 2026, saat izin masih dicatat di sick_days). Kirim components.{kode} 0
     * (izin: tambahkan dulu ke sick_days) bersama periode baru. Hitungan yang dikirim diperiksa aturan
     * components.*; data yang periodenya tidak diganti tidak diperiksa (data lama tetap bisa diubah).
     */
    private function periodeChangeRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $periode = AttendanceCountInput::periode($value);
            $attendance = $this->storedAttendance();

            if ($periode === null || $attendance === null || AttendanceCountInput::periode($attendance->periode) === $periode) {
                return;
            }

            $sent = $this->input('components');
            $stored = array_diff_key(
                is_array($attendance->component_counts) ? $attendance->component_counts : [],
                is_array($sent) ? $sent : [],
            );
            $unused = AttendanceCountInput::unusedCodes($stored, ScoringRuleService::forPeriode($periode));

            if ($unused !== []) {
                $fail(AttendanceCountInput::unusedComponentsMessage(
                    $unused,
                    $periode,
                    AttendanceCountInput::CONTEXT_API,
                    array_map(static fn (mixed $count): int => (int) $count, array_intersect_key($stored, array_flip($unused))),
                ));
            }
        };
    }

    /**
     * Data yang diubah, hanya bila user boleh mengubahnya; dibaca sekali per request (aturan components.*
     * dijalankan per kode komponen).
     */
    private function storedAttendance(): ?Attendance
    {
        if ($this->storedAttendance === false) {
            $attendance = Attendance::query()->find((int) $this->route('id'));

            $this->storedAttendance = $attendance !== null && $this->user()?->can('update', $attendance) === true
                ? $attendance
                : null;
        }

        return $this->storedAttendance;
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validasi gagal.',
            'errors' => $validator->errors(),
        ], 422));
    }
}
