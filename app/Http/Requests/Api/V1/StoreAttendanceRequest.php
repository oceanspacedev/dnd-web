<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ValidatesAttendanceCounts;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreAttendanceRequest extends FormRequest
{
    use ValidatesAttendanceCounts;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            // Dinormalisasi ke 'Y-m' sebelum validasi (prepareForValidation).
            'periode' => ['required', ...$this->periodeRules()],
            'work_days' => ['required', ...$this->workDayRules()],
            // late_less_30 / late_more_30 / sick_days dan components {kode: jumlah}.
            ...$this->countRules(),
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.required' => 'Karyawan wajib dipilih.',
            'user_id.integer' => 'Karyawan tidak valid.',
            'periode.required' => 'Periode presensi (e.g. 2026-09) wajib diisi.',
            'work_days.required' => 'Jumlah hari kerja wajib diisi.',
            ...$this->countMessages(),
        ];
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
