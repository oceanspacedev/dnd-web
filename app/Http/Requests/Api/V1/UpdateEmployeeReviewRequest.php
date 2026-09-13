<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateEmployeeReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'periode' => ['sometimes', 'required', 'string', 'max:50'],
            'responsiveness' => ['sometimes', 'required', 'integer', 'min:1', 'max:5'],
            'problem_solver' => ['sometimes', 'required', 'integer', 'min:1', 'max:5'],
            'helpfulness' => ['sometimes', 'required', 'integer', 'min:1', 'max:5'],
            'initiative' => ['sometimes', 'required', 'integer', 'min:1', 'max:5'],
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
