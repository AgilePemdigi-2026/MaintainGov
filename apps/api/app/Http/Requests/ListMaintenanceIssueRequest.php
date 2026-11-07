<?php

namespace App\Http\Requests;

use App\Services\Maintenance\MaintenanceIssueService;
use Illuminate\Validation\Rule;

class ListMaintenanceIssueRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in(MaintenanceIssueService::statuses())],
            'assigned_to_me' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
