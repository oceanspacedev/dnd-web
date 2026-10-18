<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Query GET /master/scoring-rules/resolve. Periode ketat 'Y-m' seperti endpoint analytics; error memakai
 * envelope API {success: false, message, errors}.
 */
class ResolveScoringRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'periode' => ['sometimes', 'nullable', 'string', 'date_format:Y-m'],
        ];
    }

    public function messages(): array
    {
        return [
            'periode.string' => 'Periode harus berformat tahun-bulan YYYY-MM (mis. 2026-11).',
            'periode.date_format' => 'Periode harus berformat tahun-bulan YYYY-MM (mis. 2026-11).',
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
