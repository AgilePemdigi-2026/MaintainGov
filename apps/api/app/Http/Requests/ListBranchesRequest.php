<?php

namespace App\Http\Requests;

class ListBranchesRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
