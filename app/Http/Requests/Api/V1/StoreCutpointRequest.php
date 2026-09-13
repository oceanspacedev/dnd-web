<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreCutpointRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'exists:users,id'],
            'point' => ['required', 'numeric', 'min:0'],
            'periode' => ['required', 'string', 'max:50'],
            'keterangan' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.required' => 'Karyawan yang dipotong poin wajib dipilih.',
            'point.required' => 'Besaran poin potongan wajib diisi.',
            'periode.required' => 'Periode (e.g. 2026-09) wajib diisi.',
            'keterangan.required' => 'Keterangan/alasan pemotongan poin wajib diisi.',
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
