<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateDailyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'task' => ['sometimes', 'required', 'string'],
            'date' => ['sometimes', 'required', 'date'],
            'time' => ['nullable', 'string', 'max:50'],
            'tipe' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'integer'],
            'ontime' => ['boolean'],
            'isplan' => ['boolean'],
            'isupdate' => ['boolean'],
            'task_category_id' => ['nullable', 'exists:task_categories,id'],
            'task_status_id' => ['nullable', 'exists:task_status,id'],
            'tag_id' => ['nullable', 'exists:users,id'],
            'value_plan' => ['nullable', 'numeric', 'min:0'],
            'value_actual' => ['nullable', 'numeric', 'min:0'],
            'status_result' => ['nullable', 'string', 'max:100'],
            'value' => ['nullable', 'string', 'max:255'],
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
