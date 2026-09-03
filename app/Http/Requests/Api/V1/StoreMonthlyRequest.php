<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreMonthlyRequest extends FormRequest
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
            'tipe' => ['nullable', 'string', 'max:100'],
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
            'task.required' => 'Nama/deskripsi rencana bulanan wajib diisi.',
            'date.required' => 'Tanggal/bulan rencana wajib diisi.',
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
