<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:255', 'unique:users,username'],
            'nama_lengkap' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'no_hp' => ['nullable', 'string', 'max:50'],
            'employee_id' => ['nullable', 'string', 'max:100', 'unique:users,employee_id'],
            'role_id' => ['required', 'exists:roles,id'],
            'area_id' => ['nullable', 'exists:areas,id'],
            'divisi_id' => ['nullable', 'exists:divisis,id'],
            'position_id' => ['nullable', 'exists:positions,id'],
            'approval_id' => ['nullable', 'exists:users,id'],
            'dr' => ['boolean'],
            'wn' => ['boolean'],
            'wr' => ['boolean'],
            'mn' => ['boolean'],
            'mr' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'username.required' => 'Username wajib diisi.',
            'username.unique' => 'Username sudah digunakan.',
            'nama_lengkap.required' => 'Nama lengkap wajib diisi.',
            'email.email' => 'Format email tidak valid.',
            'email.unique' => 'Email sudah digunakan.',
            'password.required' => 'Kata sandi wajib diisi.',
            'password.min' => 'Kata sandi minimal :min karakter.',
            'role_id.required' => 'Role wajib dipilih.',
            'role_id.exists' => 'Role yang dipilih tidak valid.',
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
