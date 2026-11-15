<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class ListPullRequestsRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in(['open', 'closed', 'merged', 'all'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function attributes(): array
    {
        return [
            'status' => 'status Pull Request',
            'search' => 'pencarian',
            'page' => 'halaman',
            'per_page' => 'jumlah per halaman',
        ];
    }
}
