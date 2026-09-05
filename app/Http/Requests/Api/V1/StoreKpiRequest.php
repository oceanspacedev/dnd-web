<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreKpiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'exists:users,id'],
            'kpi_category_id' => ['required', 'exists:kpi_categories,id'],
            'kpi_type_id' => ['required', 'exists:kpi_types,id'],
            'date' => ['required', 'date'],
            'percentage' => ['required', 'numeric', 'min:1', 'max:100'],
            'details' => ['nullable', 'array'],
            'details.*.kpi_description_id' => ['required_with:details', 'exists:kpi_descriptions,id'],
            'details.*.parent_id' => ['nullable', 'exists:kpi_details,id'],
            'details.*.count_type' => ['nullable', 'string', 'max:50'],
            'details.*.value_plan' => ['nullable', 'numeric', 'min:0'],
            'details.*.value_actual' => ['nullable', 'numeric', 'min:0'],
            'details.*.subtasks' => ['nullable', 'array'],
            'details.*.is_extra_task' => ['boolean'],
            'details.*.start' => ['nullable', 'date'],
            'details.*.end' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.required' => 'Karyawan wajib dipilih.',
            'user_id.exists' => 'Karyawan tidak valid.',
            'kpi_category_id.required' => 'Kategori KPI wajib dipilih.',
            'kpi_category_id.exists' => 'Kategori KPI tidak valid.',
            'kpi_type_id.required' => 'Tipe KPI wajib dipilih.',
            'kpi_type_id.exists' => 'Tipe KPI tidak valid.',
            'date.required' => 'Tanggal periode KPI wajib diisi.',
            'percentage.required' => 'Bobot persentase KPI wajib diisi.',
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
