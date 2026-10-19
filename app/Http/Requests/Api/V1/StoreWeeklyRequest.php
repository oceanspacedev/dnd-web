<?php

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use App\Services\DataAccessService;
use Closure;
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
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'task' => ['required', 'string'],
            'week' => ['required', 'integer', 'min:1', 'max:53'],
            'year' => ['required', 'integer', 'min:2020', 'max:2099'],
            'tipe' => ['nullable', 'string', 'max:100'],
            'task_category_id' => ['nullable', 'exists:task_categories,id'],
            'task_status_id' => ['nullable', 'exists:task_status,id'],
            'tag_id' => ['nullable', 'integer', 'exists:users,id', $this->visibleToAreaScopedAdmin()],
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

    /**
     * Admin per badan usaha hanya boleh merujuk karyawan yang terlihat olehnya
     * (badan usaha yang diizinkan, tim approval, atau dirinya sendiri).
     */
    private function visibleToAreaScopedAdmin(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $actor = $this->user();

            if (
                filled($value)
                && is_scalar($value)
                && $actor instanceof User
                && DataAccessService::isAreaScopedAdmin($actor)
                && ! DataAccessService::canSeeUser($actor, (string) $value)
            ) {
                $fail('Karyawan yang ditandai berada di luar akses data Anda.');
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
