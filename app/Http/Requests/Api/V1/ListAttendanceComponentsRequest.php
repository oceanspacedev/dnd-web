<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Query GET /master/attendance-components. Filter ?active menerima 1/0 dan true/false (nilai yang umum
 * dikirim klien HTTP di query string); error memakai envelope API {success: false, message, errors}.
 */
class ListAttendanceComponentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'active' => ['sometimes', 'in:0,1,true,false'],
        ];
    }

    public function messages(): array
    {
        return [
            'active.in' => 'Filter active harus 1, 0, true, atau false.',
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
