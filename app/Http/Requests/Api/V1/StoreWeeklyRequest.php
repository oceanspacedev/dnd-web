<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreWeeklyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'exists:users,id'],
            'task' => ['required', 'string'],
            'week' => ['required', 'integer', 'min:1', 'max:53'],
            'year' => ['required', 'integer', 'min:2020', 'max:2099'],
            'tipe' => ['nullable', 'string', 'max:100'],
            'task_category_id' => ['nullable', 'exists:task_categories,id'],
            'task_status_id' => ['nullable', 'exists:task_status,id'],
            'tag_id' => ['nullable', 'exists:users,id'],
            'value_plan' => ['nullable', 'numeric', 'min:0'],
            'value_actual' => ['nullable', 'numeric', 'min:0'],
            'status_non' => ['nullable', 'string', 'max:100'],
            'status_result' => ['nullable', 'string', 'max:100'],
            'value' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'task.required' => 'Nama/deskripsi rencana mingguan wajib diisi.',
            'week.required' => 'Nomor minggu (1-53) wajib diisi.',
            'year.required' => 'Tahun wajib diisi.',
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
