<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class TriggerReminderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) (auth()->user()?->role?->name === 'ADMIN');
    }

    public function rules(): array
    {
        return [
            'setting_id' => ['nullable', 'exists:kpi_reminder_settings,id'],
            'dry_run' => ['nullable', 'boolean'],
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
