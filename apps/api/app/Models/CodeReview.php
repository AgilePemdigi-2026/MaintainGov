<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * UC06 Code Review. One active review per (pull_request, reviewer): a new
 * review by the same reviewer replaces the previous row (D-W14-01-c).
 * sync_status records whether the decision reached GitHub; only a synced
 * decision may change PR/backlog status (D-W14-01-e).
 */
class CodeReview extends Model
{
    public const DECISION_APPROVE = 'Approved';

    public const DECISION_REQUEST_CHANGES = 'Changes Requested';

    protected $table = 'code_review';

    protected $primaryKey = 'review_id';

    protected $fillable = [
        'pull_request_id',
        'reviewer_id',
        'decision',
        'comment',
        'reviewed_at',
        'github_review_id',
        'sync_status',
        'synced_at',
        'last_sync_error_code',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'synced_at' => 'datetime',
        'github_review_id' => 'integer',
    ];

    public function pullRequest(): BelongsTo
    {
        return $this->belongsTo(PullRequest::class, 'pull_request_id', 'pull_request_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Pengguna::class, 'reviewer_id', 'user_id');
    }
}
