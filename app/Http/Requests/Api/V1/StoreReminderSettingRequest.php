<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreReminderSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) (auth()->user()?->role?->name === 'ADMIN');
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'in:pembuatan_kpi,pengisian_kpi'],
            'title' => ['required', 'string', 'max:255'],
            'deadline_day' => ['required', 'integer', 'min:1', 'max:31'],
            'reminder_days_before' => ['nullable', 'array'],
            'reminder_days_before.*' => ['integer', 'min:0', 'max:30'],
            'send_overdue_reminder' => ['nullable', 'boolean'],
            'send_email' => ['nullable', 'boolean'],
            'send_whatsapp' => ['nullable', 'boolean'],
            'email_template' => ['nullable', 'string'],
            'email_body' => ['nullable', 'string'],
            'whatsapp_template' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.required' => 'Tipe reminder (pembuatan_kpi / pengisian_kpi) wajib diisi.',
            'title.required' => 'Judul aturan reminder wajib diisi.',
            'deadline_day.required' => 'Tanggal tenggat (1-31) wajib diisi.',
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
