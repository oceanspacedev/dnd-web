<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateKpiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['sometimes', 'required', 'exists:users,id'],
            'kpi_category_id' => ['sometimes', 'required', 'exists:kpi_categories,id'],
            'kpi_type_id' => ['sometimes', 'required', 'exists:kpi_types,id'],
            'date' => ['sometimes', 'required', 'date'],
            'percentage' => ['sometimes', 'required', 'numeric', 'min:1', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'percentage.min' => 'Bobot persentase minimal 1%.',
            'percentage.max' => 'Bobot persentase maksimal 100%.',
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
