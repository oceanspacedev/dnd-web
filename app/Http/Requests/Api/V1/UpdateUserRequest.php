<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Role;
use App\Models\User;
use App\Policies\AttendancePolicy;
use App\Services\DataAccessService;
use App\Support\WhatsAppNumber;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    /** Dipakai untuk id yang tidak ada maupun di luar akses data. */
    public const APPROVAL_OUT_OF_SCOPE = 'Approval line tidak valid atau berada di luar akses data Anda.';

    public const RESERVED_USERNAME = 'Username ini khusus dan hanya dapat dipakai admin dengan akses seluruh area.';

    private ?User $targetUser = null;

    private bool $targetResolved = false;

    public function authorize(): bool
    {
        $target = $this->targetUser();
        $user = $this->user();

        if (! $user instanceof User) {
            return false;
        }

        return $target instanceof User
            && (! $this->exists('no_hp') || DataAccessService::isAdmin($user))
            // Akses data badan usaha hanya diatur admin dengan akses seluruh badan usaha.
            && (! $this->sendsDataAccess() || DataAccessService::hasGlobalAccess($user))
            && $user->can('update', $target)
            // Data login akun yang aksesnya melampaui cakupan actor hanya diubah super admin (sama dengan form Filament).
            && (! $this->changesCredentials($target) || $user->can('changeCredentials', $target));
    }

    /**
     * Payload mengubah username, password, atau No. HP (login WhatsApp) karyawan.
     */
    private function changesCredentials(User $target): bool
    {
        if (filled($this->input('password'))) {
            return true;
        }

        if ($this->exists('username') && (string) $this->input('username') !== (string) $target->username) {
            return true;
        }

        return $this->exists('no_hp') && (string) $this->input('no_hp') !== (string) $target->no_hp;
    }

    public function rules(): array
    {
        $userId = $this->route('id') ?? $this->route('user');
        $actor = $this->user();
        $isScopedAdmin = DataAccessService::isAreaScopedAdmin($actor instanceof User ? $actor : null);

        return [
            'username' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('users', 'username')->ignore($userId), $this->globalAttendanceUsernameAllowed()],
            'nama_lengkap' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'password' => ['nullable', 'string', 'min:6'],
            'no_hp' => [
                'sometimes',
                'nullable',
                'string',
                'regex:/^08\d{8,12}$/',
                Rule::unique('users', 'no_hp')->ignore($userId),
            ],
            'employee_id' => ['nullable', 'string', 'max:100', Rule::unique('users', 'employee_id')->ignore($userId)],
            'role_id' => ['bail', 'sometimes', 'required', 'exists:roles,id', $this->keepsLastGlobalAdmin()],
            // Admin badan usaha tidak boleh memindahkan karyawan ke badan usaha di luar aksesnya.
            'area_id' => $isScopedAdmin
                ? ['bail', 'sometimes', 'required', 'exists:areas,id', $this->areaWithinScope()]
                : ['nullable', 'exists:areas,id'],
            'divisi_id' => ['nullable', 'exists:divisis,id'],
            'position_id' => ['nullable', 'exists:positions,id'],
            'approval_id' => ['bail', 'nullable', 'integer', 'exists:users,id', $this->approvalWithinScope()],
            'data_access' => ['bail', 'nullable', 'string', Rule::in([User::DATA_ACCESS_ALL, User::DATA_ACCESS_SELECTED]), $this->notRestrictingOwnAccess()],
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
            'username.unique' => 'Username sudah digunakan.',
            'email.email' => 'Format email tidak valid.',
            'email.unique' => 'Email sudah digunakan.',
            'password.min' => 'Kata sandi minimal :min karakter.',
            'no_hp.regex' => 'Format No. HP WhatsApp Indonesia tidak valid.',
            'no_hp.unique' => 'No. HP sudah digunakan oleh user lain.',
            'role_id.exists' => 'Role yang dipilih tidak valid.',
            'area_id.required' => 'Area wajib dipilih.',
            'area_id.exists' => 'Area yang dipilih tidak valid.',
            // Pesan sama dengan id di luar akses agar tidak membocorkan id karyawan yang ada.
            'approval_id.exists' => self::APPROVAL_OUT_OF_SCOPE,
            'data_access.in' => 'Akses data harus all atau selected.',
            'data_access_area_ids.required' => 'Pilih minimal satu area untuk akses data selected.',
            'data_access_area_ids.*.exists' => 'Area akses data tidak valid.',
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

    /**
     * Username user khusus kehadiran seluruh badan usaha hanya boleh dipakai super admin; nilai yang tidak berubah tetap boleh.
     */
    private function globalAttendanceUsernameAllowed(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_scalar($value) && (string) $value === (string) $this->targetUser()?->username) {
                return;
            }

            $actor = $this->user();

            if (AttendancePolicy::isGlobalAttendanceUsername($value) && ! ($actor instanceof User && $actor->can('useGlobalAttendanceUsername', User::class))) {
                $fail(self::RESERVED_USERNAME);
            }
        };
    }

    private function targetUser(): ?User
    {
        if (! $this->targetResolved) {
            $userId = $this->route('id') ?? $this->route('user');
            $this->targetUser = User::query()->find($userId);
            $this->targetResolved = true;
        }

        return $this->targetUser;
    }

    private function sendsDataAccess(): bool
    {
        return $this->exists('data_access') || $this->exists('data_access_area_ids');
    }

    /**
     * Role akhir karyawan (role baru di payload, atau role saat ini) adalah ADMIN.
     */
    private function assignsAdminRole(): bool
    {
        $roleId = $this->input('role_id', $this->targetUser()?->role_id);

        return is_scalar($roleId)
            && Role::query()->whereKey($roleId)->value('name') === User::ROLE_ADMIN;
    }

    private function areaWithinScope(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            // Badan usaha yang tidak berubah tetap boleh (mis. anggota tim di badan usaha lain).
            if (is_scalar($value) && (string) $value === (string) $this->targetUser()?->area_id) {
                return;
            }

            $actor = $this->user();

            if (! DataAccessService::canAccessArea($actor instanceof User ? $actor : null, is_scalar($value) ? (string) $value : null)) {
                $fail('Area berada di luar akses data Anda.');
            }
        };
    }

    /**
     * Approval line baru harus karyawan yang terlihat oleh actor; nilai yang tidak berubah tetap boleh.
     *
     * Menjadikan diri sendiri approval line memasukkan bawahan langsung karyawan ini ke tim actor,
     * jadi admin badan usaha hanya boleh bila seluruh bawahan langsungnya sudah terlihat olehnya.
     * Tanpa cek ini admin badan usaha bisa menarik karyawan badan usaha lain ke timnya.
     * Sama dengan UserResource::approvalWidensActorScope; aturan atasan non-admin tidak berubah.
     */
    private function approvalWithinScope(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_scalar($value) && (string) $value === (string) $this->targetUser()?->approval_id) {
                return;
            }

            $actor = $this->user();
            $actor = $actor instanceof User ? $actor : null;

            // Hanya admin badan usaha tertentu yang dibatasi; aturan atasan lain tetap seperti sebelumnya.
            if (! DataAccessService::isAreaScopedAdmin($actor)) {
                return;
            }

            if (! is_scalar($value) || ! DataAccessService::canSeeUser($actor, (int) $value)) {
                $fail(self::APPROVAL_OUT_OF_SCOPE);

                return;
            }

            if (
                $actor instanceof User
                && (int) $value === (int) $actor->getKey()
                && DataAccessService::isAreaScopedAdmin($actor)
                && $this->hasDirectReportsOutsideScope($actor)
            ) {
                $fail('Anda tidak dapat menjadi approval line karyawan ini karena bawahannya berada di luar akses data Anda.');
            }
        };
    }

    /**
     * Bawahan langsung (aktif) karyawan target yang tidak terlihat oleh actor.
     */
    private function hasDirectReportsOutsideScope(User $actor): bool
    {
        $target = $this->targetUser();

        if (! $target instanceof User) {
            return false;
        }

        return User::query()
            ->where('approval_id', $target->getKey())
            ->pluck('id')
            ->contains(fn (mixed $id): bool => ! DataAccessService::canSeeUser($actor, (int) $id));
    }

    /**
     * Akses data akhir setelah update: nilai yang dikirim, atau nilai saat ini.
     */
    private function resultingDataAccess(): string
    {
        $requested = $this->input('data_access');

        if (is_string($requested) && $requested !== '') {
            return $requested;
        }

        return $this->targetUser()?->data_access === User::DATA_ACCESS_SELECTED
            ? User::DATA_ACCESS_SELECTED
            : User::DATA_ACCESS_ALL;
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

            if ($this->resultingDataAccess() === User::DATA_ACCESS_ALL) {
                $fail('Area akses data hanya dapat diisi untuk akses data selected.');
            }
        };
    }

    /**
     * Super admin tidak boleh membatasi akses data akunnya sendiri (sama seperti form Filament).
     */
    private function notRestrictingOwnAccess(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === User::DATA_ACCESS_SELECTED && $this->targetsActor()) {
                $fail('Anda tidak dapat membatasi akses data akun Anda sendiri.');
            }
        };
    }

    /**
     * Super admin terakhir tidak boleh melepas role ADMIN dari akunnya sendiri.
     */
    private function keepsLastGlobalAdmin(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $target = $this->targetUser();

            if (! $target instanceof User || ! $this->targetsActor() || ! DataAccessService::hasGlobalAccess($target)) {
                return;
            }

            if (is_scalar($value) && Role::query()->whereKey($value)->value('name') === User::ROLE_ADMIN) {
                return;
            }

            if (! DataAccessService::anotherGlobalAdminExists($target)) {
                $fail('Minimal harus ada satu admin dengan akses seluruh area.');
            }
        };
    }

    private function targetsActor(): bool
    {
        $actor = $this->user();
        $target = $this->targetUser();

        return $actor instanceof User && $target instanceof User && $target->is($actor);
    }
}
