<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CodeReview extends Model
{
    public const STATE_APPROVED = 'APPROVED';

    public const STATE_CHANGES_REQUESTED = 'CHANGES_REQUESTED';

    public const STATE_COMMENTED = 'COMMENTED';

    public const STATE_DISMISSED = 'DISMISSED';

    public const STATE_PENDING = 'PENDING';

    public const DECISION_APPROVE = 'APPROVE';

    public const DECISION_REQUEST_CHANGES = 'REQUEST_CHANGES';

    protected $table = 'code_review';

    protected $primaryKey = 'code_review_id';

    protected $fillable = [
        'pull_request_id',
        'backlog_id',
        'reviewer_id',
        'reviewer_login',
        'github_review_id',
        'github_state',
        'body',
        'commit_id',
        'html_url',
        'source',
        'request_fingerprint',
        'submitted_at',
        'synced_at',
    ];

    protected $casts = [
        'github_review_id' => 'integer',
        'submitted_at' => 'datetime',
        'synced_at' => 'datetime',
    ];

    public function pullRequest(): BelongsTo
    {
        return $this->belongsTo(PullRequest::class, 'pull_request_id', 'pull_request_id');
    }

    public function backlog(): BelongsTo
    {
        return $this->belongsTo(MaintenanceBacklog::class, 'backlog_id', 'backlog_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Pengguna::class, 'reviewer_id', 'user_id');
    }
}
