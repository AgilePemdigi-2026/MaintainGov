<?php

namespace App\Http\Requests;

class StorePullRequestReviewRequest extends ApiFormRequest
{
    /**
     * UC06 (FR-UC06-08/10, BR-19): the rework note is required for
     * Request Changes and optional for Approve.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', 'in:approve,request_changes'],
            'comment' => [
                'nullable',
                'string',
                'max:5000',
                'required_if:decision,request_changes',
                'filled',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'decision' => 'keputusan review',
            'comment' => 'catatan perbaikan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'decision.required' => 'Keputusan review wajib diisi.',
            'decision.in' => 'Keputusan review harus approve atau request_changes.',
            'comment.required_if' => 'Catatan perbaikan wajib diisi ketika memilih Request Changes.',
            'comment.max' => 'Catatan perbaikan maksimal 5000 karakter.',
        ];
    }
}
