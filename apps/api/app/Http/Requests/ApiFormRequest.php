<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Form Request with a consistent JSON validation envelope for UC04/UC05.
 * Validation messages still use Laravel's translated `errors` object so the
 * existing frontend and `assertJsonValidationErrors()` keep working.
 */
abstract class ApiFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Data yang diberikan tidak valid.',
            'error' => [
                'code' => 'VALIDATION_ERROR',
                'message' => 'Data yang diberikan tidak valid.',
            ],
            'errors' => $validator->errors(),
        ], 422));
    }
}
