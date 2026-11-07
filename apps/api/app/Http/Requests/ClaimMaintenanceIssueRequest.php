<?php

namespace App\Http\Requests;

class ClaimMaintenanceIssueRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            // Optional: a Developer may choose the working branch at claim time.
            // When omitted, the backend assigns a deterministic branch and
            // returns it in the response.
            'source_branch' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
                'regex:/^(?!-)(?!.*\.\.)(?!.*\/\/)(?!.*[\/.]$)[A-Za-z0-9._\/-]+$/',
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'source_branch' => 'branch sumber',
        ];
    }

    public function messages(): array
    {
        return [
            'source_branch.regex' => 'Format branch sumber tidak valid.',
        ];
    }
}
