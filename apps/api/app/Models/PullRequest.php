<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PullRequest extends Model
{
    protected $table = 'pull_request';

    protected $primaryKey = 'pull_request_id';

    protected $fillable = [
        'backlog_id',
        'developer_id',
        'repository_id',
        'repository_full_name',
        'source_branch',
        'target_branch',
        'title',
        'description',
        'github_pr_id',
        'github_pr_number',
        'github_pr_url',
        'github_head_sha',
        'github_base_sha',
        'submission_state',
        'lifecycle_state',
        'review_state',
        'request_fingerprint',
        'idempotency_key',
        'last_error_code',
        'reconciled_at',
    ];

    protected $casts = [
        'github_pr_id' => 'integer',
        'github_pr_number' => 'integer',
        'reconciled_at' => 'datetime',
    ];

    public function backlog(): BelongsTo
    {
        return $this->belongsTo(MaintenanceBacklog::class, 'backlog_id', 'backlog_id');
    }

    public function developer(): BelongsTo
    {
        return $this->belongsTo(Pengguna::class, 'developer_id', 'user_id');
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(GitHubRepository::class, 'repository_id', 'repository_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(CodeReview::class, 'pull_request_id', 'pull_request_id');
    }
}
