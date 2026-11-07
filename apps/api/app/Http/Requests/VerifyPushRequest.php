<?php

namespace App\Http\Requests;

class VerifyPushRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            // Optional expected SHA. It is never trusted as proof: the backend
            // reads the branch head and commit directly from GitHub.
            'commit_sha' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9a-fA-F]{40}$/'],
        ];
    }

    public function attributes(): array
    {
        return [
            'commit_sha' => 'commit SHA',
        ];
    }

    public function messages(): array
    {
        return [
            'commit_sha.regex' => 'Commit SHA harus berupa 40 karakter heksadesimal.',
        ];
    }
}
