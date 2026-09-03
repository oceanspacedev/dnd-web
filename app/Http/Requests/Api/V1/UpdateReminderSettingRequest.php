<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateReminderSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'required', 'string', 'in:pembuatan_kpi,pengisian_kpi'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'deadline_day' => ['sometimes', 'required', 'integer', 'min:1', 'max:31'],
            'reminder_days_before' => ['nullable', 'array'],
            'reminder_days_before.*' => ['integer', 'min:1', 'max:30'],
            'send_overdue_reminder' => ['nullable', 'boolean'],
            'send_email' => ['nullable', 'boolean'],
            'send_whatsapp' => ['nullable', 'boolean'],
            'email_template' => ['nullable', 'string'],
            'whatsapp_template' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
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
