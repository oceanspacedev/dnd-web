<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreDailyRequest extends FormRequest
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
            'date' => ['required', 'date'],
            'time' => ['nullable', 'string', 'max:50'],
            'tipe' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'integer'],
            'ontime' => ['boolean'],
            'isplan' => ['boolean'],
            'task_category_id' => ['nullable', 'exists:task_categories,id'],
            'task_status_id' => ['nullable', 'exists:task_status,id'],
            'tag_id' => ['nullable', 'exists:users,id'],
            'value_plan' => ['nullable', 'numeric', 'min:0'],
            'value_actual' => ['nullable', 'numeric', 'min:0'],
            'status_result' => ['nullable', 'string', 'max:100'],
            'value' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'task.required' => 'Nama/deskripsi tugas harian wajib diisi.',
            'date.required' => 'Tanggal tugas wajib diisi.',
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
