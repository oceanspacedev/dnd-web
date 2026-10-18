<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Query GET /activities/summary. user_id dinormalisasi sekali di sini: id yang diotorisasi controller harus sama
 * persis dengan id yang dipakai query (user_id[] dsb. ditolak agar (int) cast tidak memeriksa user yang berbeda).
 */
class ActivitySummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.integer' => 'Karyawan tidak valid.',
            'user_id.min' => 'Karyawan tidak valid.',
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
