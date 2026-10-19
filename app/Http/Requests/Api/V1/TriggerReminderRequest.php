<?php

namespace App\Http\Requests\Api\V1;

use App\Services\DataAccessService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class TriggerReminderRequest extends FormRequest
{
    /**
     * Khusus super admin (ADMIN dengan akses seluruh badan usaha).
     */
    public function authorize(): bool
    {
        return DataAccessService::hasGlobalAccess(auth()->user());
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
