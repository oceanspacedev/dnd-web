<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class SendTestReminderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'channel' => ['required', 'string', 'in:email,whatsapp'],
            'destination' => ['required', 'string'],
            'message' => ['nullable', 'string'],
            'setting_id' => ['nullable', 'exists:kpi_reminder_settings,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'channel.required' => 'Channel (email / whatsapp) wajib dipilih.',
            'destination.required' => 'Tujuan (email atau nomor WhatsApp) wajib diisi.',
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
