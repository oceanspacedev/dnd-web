<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Role;
use App\Models\User;
use App\Services\DataAccessService;
use App\Support\WhatsAppNumber;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && (! $this->exists('no_hp') || DataAccessService::isAdmin($user))
            // Akses data badan usaha hanya diatur admin dengan akses seluruh badan usaha.
            && (! $this->sendsDataAccess() || DataAccessService::hasGlobalAccess($user))
            && $user->can('create', User::class);
    }

    public function rules(): array
    {
        $actor = $this->user();
        $isScopedAdmin = DataAccessService::isAreaScopedAdmin($actor instanceof User ? $actor : null);

        return [
            'username' => ['required', 'string', 'max:255', 'unique:users,username'],
            'nama_lengkap' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'no_hp' => [
                'nullable',
                'string',
                'regex:/^08\d{8,12}$/',
                Rule::unique('users', 'no_hp'),
            ],
            'employee_id' => ['nullable', 'string', 'max:100', 'unique:users,employee_id'],
            'role_id' => ['required', 'exists:roles,id'],
            // Admin badan usaha hanya boleh menambah karyawan di badan usaha yang diizinkan.
            'area_id' => $isScopedAdmin
                ? ['bail', 'required', 'exists:areas,id', $this->areaWithinScope()]
                : ['nullable', 'exists:areas,id'],
            'divisi_id' => ['nullable', 'exists:divisis,id'],
            'position_id' => ['nullable', 'exists:positions,id'],
            'approval_id' => ['bail', 'nullable', 'integer', 'exists:users,id', $this->approvalWithinScope()],
            'data_access' => ['nullable', 'string', Rule::in([User::DATA_ACCESS_ALL, User::DATA_ACCESS_SELECTED])],
            'data_access_area_ids' => [
                Rule::requiredIf(fn (): bool => $this->input('data_access') === User::DATA_ACCESS_SELECTED && $this->assignsAdminRole()),
                'nullable',
                'array',
                $this->areaIdsOnlyForSelected(),
            ],
            'data_access_area_ids.*' => ['integer', 'distinct', 'exists:areas,id'],
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
            'no_hp.regex' => 'Format No. HP WhatsApp Indonesia tidak valid.',
            'no_hp.unique' => 'No. HP sudah digunakan oleh user lain.',
            'role_id.required' => 'Role wajib dipilih.',
            'role_id.exists' => 'Role yang dipilih tidak valid.',
            'area_id.required' => 'Badan usaha wajib dipilih.',
            'area_id.exists' => 'Badan usaha yang dipilih tidak valid.',
            // Pesan sama dengan id di luar akses agar tidak membocorkan id karyawan yang ada.
            'approval_id.exists' => UpdateUserRequest::APPROVAL_OUT_OF_SCOPE,
            'data_access.in' => 'Akses data harus all atau selected.',
            'data_access_area_ids.required' => 'Pilih minimal satu badan usaha untuk akses data selected.',
            'data_access_area_ids.*.exists' => 'Badan usaha akses data tidak valid.',
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

    protected function prepareForValidation(): void
    {
        $value = $this->input('no_hp');

        if (! $this->exists('no_hp') || ! is_scalar($value) || trim((string) $value) === '') {
            return;
        }

        $this->merge([
            'no_hp' => WhatsAppNumber::toLocal((string) $value) ?? $value,
        ]);
    }

    private function sendsDataAccess(): bool
    {
        return $this->exists('data_access') || $this->exists('data_access_area_ids');
    }

    private function assignsAdminRole(): bool
    {
        $roleId = $this->input('role_id');

        return is_scalar($roleId)
            && Role::query()->whereKey($roleId)->value('name') === User::ROLE_ADMIN;
    }

    private function areaWithinScope(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $actor = $this->user();

            if (! DataAccessService::canAccessArea($actor instanceof User ? $actor : null, is_scalar($value) ? (string) $value : null)) {
                $fail('Badan usaha berada di luar akses data Anda.');
            }
        };
    }

    /**
     * Approval line harus karyawan yang terlihat oleh actor (badan usaha, tim, atau diri sendiri).
     */
    private function approvalWithinScope(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $actor = $this->user();
            $actor = $actor instanceof User ? $actor : null;

            // Hanya admin badan usaha tertentu yang dibatasi; aturan atasan lain tetap seperti sebelumnya.
            if (! DataAccessService::isAreaScopedAdmin($actor)) {
                return;
            }

            if (! is_scalar($value) || ! DataAccessService::canSeeUser($actor, (int) $value)) {
                $fail(UpdateUserRequest::APPROVAL_OUT_OF_SCOPE);
            }
        };
    }

    /**
     * Daftar badan usaha hanya bermakna untuk admin dengan akses selected; jangan diabaikan diam-diam.
     */
    private function areaIdsOnlyForSelected(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value) || $value === [] || ! $this->assignsAdminRole()) {
                return;
            }

            $dataAccess = $this->input('data_access');

            if (! is_string($dataAccess) || $dataAccess === '' || $dataAccess === User::DATA_ACCESS_ALL) {
                $fail('Badan usaha akses data hanya dapat diisi untuk akses data selected.');
            }
        };
    }
}
