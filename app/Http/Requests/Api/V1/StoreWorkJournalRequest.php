<?php

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use App\Policies\WorkJournalPolicy;
use App\Services\DataAccessService;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreWorkJournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Hanya admin yang boleh mengisi user_id. Untuk karyawan di luar cakupan, keunikan tanggal
        // tidak dicek terhadap jurnal mereka (user_id ditolak oleh rule di bawah).
        $userId = $this->canChooseOwner()
            ? $this->input('user_id')
            : auth()->id();

        return [
            'user_id' => ['nullable', 'integer', 'exists:users,id', $this->visibleToAreaScopedAdmin()],
            'date' => [
                'nullable',
                'date',
                Rule::unique('work_journals', 'date')->where(function ($query) use ($userId) {
                    return $query->where('user_id', $userId)->whereNull('deleted_at');
                }),
            ],
            'activity' => ['required', 'string'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.integer' => 'Karyawan tidak valid.',
            'activity.required' => 'Uraian aktivitas yang dikerjakan hari ini wajib diisi.',
            'date.date' => 'Format tanggal tidak valid (gunakan YYYY-MM-DD).',
            'date.unique' => 'Anda sudah membuat jurnal untuk tanggal ini. Silakan edit jurnal yang sudah ada.',
        ];
    }

    /**
     * Admin boleh mengisi pemilik jurnal, selama boleh menulis jurnal karyawan tersebut
     * (super admin: semua; admin per badan usaha: badan usaha yang diizinkan, tim, diri sendiri,
     * tanpa akun ADMIN lain).
     */
    private function canChooseOwner(): bool
    {
        $actor = $this->user();
        $input = $this->input('user_id');

        return DataAccessService::isAdmin($actor)
            && $this->filled('user_id')
            && is_scalar($input)
            && WorkJournalPolicy::canWriteFor($actor, (string) $input);
    }

    /**
     * Admin per badan usaha hanya boleh membuat jurnal untuk karyawan dalam cakupannya
     * (badan usaha yang diizinkan, tim approval, atau dirinya sendiri), bukan untuk akun ADMIN lain.
     */
    private function visibleToAreaScopedAdmin(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $actor = $this->user();

            if (
                filled($value)
                && $actor instanceof User
                && DataAccessService::isAreaScopedAdmin($actor)
                && (! is_scalar($value) || ! WorkJournalPolicy::canWriteFor($actor, (string) $value))
            ) {
                $fail('Karyawan berada di luar akses data Anda.');
            }
        };
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
