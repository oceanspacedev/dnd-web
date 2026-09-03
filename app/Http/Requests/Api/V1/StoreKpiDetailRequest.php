<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreKpiDetailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kpi_description_id' => ['required', 'exists:kpi_descriptions,id'],
            'count_type' => ['nullable', 'string', 'max:50'],
            'value_plan' => ['required', 'numeric', 'min:0'],
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
            'kpi_description_id.required' => 'Deskripsi indikator KPI wajib dipilih.',
            'kpi_description_id.exists' => 'Deskripsi indikator KPI tidak valid.',
            'value_plan.required' => 'Target rencana (value_plan) wajib diisi.',
            'value_plan.min' => 'Target rencana minimal 0.',
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
