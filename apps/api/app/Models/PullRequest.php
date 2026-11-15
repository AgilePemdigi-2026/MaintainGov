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
        'author_login',
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
        'is_draft',
        'review_state',
        'merged_at',
        'closed_at',
        'github_created_at',
        'github_updated_at',
        'last_synced_at',
        'request_fingerprint',
        'idempotency_key',
        'last_error_code',
        'reconciled_at',
    ];

    protected $casts = [
        'github_pr_id' => 'integer',
        'github_pr_number' => 'integer',
        'is_draft' => 'boolean',
        'merged_at' => 'datetime',
        'closed_at' => 'datetime',
        'github_created_at' => 'datetime',
        'github_updated_at' => 'datetime',
        'last_synced_at' => 'datetime',
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
