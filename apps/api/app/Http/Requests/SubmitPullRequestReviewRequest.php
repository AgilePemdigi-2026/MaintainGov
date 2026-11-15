<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class SubmitPullRequestReviewRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        $decision = $this->input('decision');

        if (is_string($decision)) {
            $this->merge(['decision' => strtoupper(trim($decision))]);
        }

        $notes = $this->input('notes');

        if (is_string($notes)) {
            $this->merge(['notes' => trim($notes)]);
        }

        $headerKey = $this->header('Idempotency-Key');

        if (is_string($headerKey) && trim($headerKey) !== '') {
            $this->merge(['idempotency_key' => trim($headerKey)]);
        }
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::in(['APPROVE', 'REQUEST_CHANGES'])],
            'notes' => [
                'nullable',
                'string',
                'max:5000',
                'required_if:decision,REQUEST_CHANGES',
            ],
            'idempotency_key' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
                'regex:/^[A-Za-z0-9._:-]+$/',
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'decision' => 'keputusan review',
            'notes' => 'catatan perbaikan',
            'idempotency_key' => 'idempotency key',
        ];
    }

    public function messages(): array
    {
        return [
            'decision.in' => 'Keputusan review harus APPROVE atau REQUEST_CHANGES.',
            'notes.required_if' => 'Catatan perbaikan wajib diisi untuk Request Changes.',
        ];
    }
}
