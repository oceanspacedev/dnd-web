<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateKpiDetailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kpi_description_id' => ['sometimes', 'required', 'exists:kpi_descriptions,id'],
            'parent_id' => ['nullable', 'exists:kpi_details,id'],
            'count_type' => ['nullable', 'string', 'max:50'],
            'value_plan' => ['sometimes', 'required', 'numeric', 'min:0'],
            'value_actual' => ['nullable', 'numeric', 'min:0'],
            'subtasks' => ['nullable', 'array'],
            'is_extra_task' => ['boolean'],
            'start' => ['nullable', 'date'],
            'end' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'value_plan.min' => 'Target rencana minimal 0.',
            'value_actual.min' => 'Realisasi aktual minimal 0.',
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
