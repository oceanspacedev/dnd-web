<?php

namespace App\Http\Requests\Api\V1;

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
        $userId = auth()->user()?->role?->name === 'ADMIN' && $this->filled('user_id')
            ? $this->input('user_id')
            : auth()->id();

        return [
            'user_id' => ['nullable', 'exists:users,id'],
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
            'activity.required' => 'Uraian aktivitas yang dikerjakan hari ini wajib diisi.',
            'date.date' => 'Format tanggal tidak valid (gunakan YYYY-MM-DD).',
            'date.unique' => 'Anda sudah membuat jurnal untuk tanggal ini. Silakan edit jurnal yang sudah ada.',
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
