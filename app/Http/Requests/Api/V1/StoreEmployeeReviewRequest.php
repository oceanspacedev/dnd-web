<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreEmployeeReviewRequest extends FormRequest
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
            'responsiveness' => ['required', 'integer', 'min:1', 'max:5'],
            'problem_solver' => ['required', 'integer', 'min:1', 'max:5'],
            'helpfulness' => ['required', 'integer', 'min:1', 'max:5'],
            'initiative' => ['required', 'integer', 'min:1', 'max:5'],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.required' => 'Karyawan wajib dipilih.',
            'periode.required' => 'Periode review (e.g. 2026-09) wajib diisi.',
            'responsiveness.required' => 'Rating responsiveness (1-5) wajib diisi.',
            'problem_solver.required' => 'Rating problem solver (1-5) wajib diisi.',
            'helpfulness.required' => 'Rating helpfulness (1-5) wajib diisi.',
            'initiative.required' => 'Rating initiative (1-5) wajib diisi.',
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
