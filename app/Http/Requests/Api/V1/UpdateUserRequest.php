<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('id') ?? $this->route('user');

        return [
            'username' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('users', 'username')->ignore($userId)],
            'nama_lengkap' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'password' => ['nullable', 'string', 'min:6'],
            'no_hp' => ['nullable', 'string', 'max:50'],
            'employee_id' => ['nullable', 'string', 'max:100', Rule::unique('users', 'employee_id')->ignore($userId)],
            'role_id' => ['sometimes', 'required', 'exists:roles,id'],
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
            'username.unique' => 'Username sudah digunakan.',
            'email.email' => 'Format email tidak valid.',
            'email.unique' => 'Email sudah digunakan.',
            'password.min' => 'Kata sandi minimal :min karakter.',
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
