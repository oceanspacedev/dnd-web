<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'exists:users,id'],
            'periode' => ['required', 'string', 'max:50'],
            'work_days' => ['required', 'integer', 'min:0'],
            'late_less_30' => ['nullable', 'integer', 'min:0'],
            'late_more_30' => ['nullable', 'integer', 'min:0'],
            'sick_days' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.required' => 'Karyawan wajib dipilih.',
            'periode.required' => 'Periode presensi (e.g. 2026-09) wajib diisi.',
            'work_days.required' => 'Jumlah hari kerja wajib diisi.',
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
