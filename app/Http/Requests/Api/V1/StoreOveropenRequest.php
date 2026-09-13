<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreOveropenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'exists:users,id'],
            'atasan' => ['nullable', 'exists:users,id'],
            'week' => ['required', 'integer', 'min:1', 'max:53'],
            'year' => ['required', 'integer', 'min:2020', 'max:2099'],
            'daily' => ['nullable', 'integer', 'min:0'],
            'weekly' => ['nullable', 'integer', 'min:0'],
            'monthly' => ['nullable', 'integer', 'min:0'],
            'point' => ['nullable', 'numeric', 'min:0'],
            'keterangan' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.required' => 'Karyawan wajib dipilih.',
            'week.required' => 'Minggu ke-X wajib diisi.',
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
