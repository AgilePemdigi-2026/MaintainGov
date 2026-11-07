<?php

namespace App\Http\Requests;

class StorePullRequestRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        $headerKey = $this->header('Idempotency-Key');

        if (is_string($headerKey) && trim($headerKey) !== '') {
            $this->merge(['idempotency_key' => trim($headerKey)]);
        }
    }

    public function rules(): array
    {
        $branchRule = [
            'required',
            'string',
            'max:255',
            'regex:/^(?!-)(?!.*\.\.)(?!.*\/\/)(?!.*[\/.]$)[A-Za-z0-9._\/-]+$/',
        ];

        return [
            'source_branch' => $branchRule,
            'target_branch' => array_merge($branchRule, ['different:source_branch']),
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
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
            'source_branch' => 'branch sumber',
            'target_branch' => 'branch tujuan',
            'title' => 'judul Pull Request',
            'description' => 'deskripsi Pull Request',
            'idempotency_key' => 'idempotency key',
        ];
    }

    public function messages(): array
    {
        return [
            'source_branch.regex' => 'Format branch sumber tidak valid.',
            'target_branch.regex' => 'Format branch tujuan tidak valid.',
            'target_branch.different' => 'Branch tujuan harus berbeda dari branch sumber.',
            'idempotency_key.regex' => 'Format idempotency key tidak valid.',
        ];
    }
}
