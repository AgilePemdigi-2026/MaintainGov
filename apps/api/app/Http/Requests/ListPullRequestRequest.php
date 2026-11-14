<?php

namespace App\Http\Requests;

class ListPullRequestRequest extends ApiFormRequest
{
    /**
     * Filter values follow the SRS status labels (contracts/uc06-api.md §4.1).
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', 'string', 'in:Open,Approved,Changes Requested,Merged,Closed'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'status' => 'filter status',
            'page' => 'halaman',
            'per_page' => 'jumlah per halaman',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in' => 'Filter status harus Open, Approved, Changes Requested, Merged, atau Closed.',
        ];
    }
}
