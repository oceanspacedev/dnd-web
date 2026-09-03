<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'exists:users,id'],
            'jenistodo' => ['required', 'string', 'in:daily,weekly,monthly,Daily,Weekly,Monthly'],
            'todo_request' => ['required', 'string'],
            'todo_replace' => ['nullable', 'string'],
            'approval_id' => ['nullable', 'exists:users,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'jenistodo.required' => 'Jenis tugas (daily, weekly, monthly) wajib diisi.',
            'jenistodo.in' => 'Jenis tugas harus daily, weekly, atau monthly.',
            'todo_request.required' => 'Nama/deskripsi tugas yang diajukan wajib diisi.',
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
