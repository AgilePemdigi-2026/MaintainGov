<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaintenanceBacklog extends Model
{
    public const STATUS_READY_DEVELOPMENT = 'Ready for Development';

    public const STATUS_IN_PROGRESS = 'Sedang Dikerjakan';

    public const STATUS_READY_PULL_REQUEST = 'Ready for Pull Request';

    public const STATUS_DONE = 'Selesai';

    protected $table = 'maintenance_backlog';

    protected $primaryKey = 'backlog_id';

    protected $fillable = [
        'case_id',
        'repository_id',
        'assigned_to',
        'assigned_at',
        'source_branch',
        'last_push_sha',
        'last_push_at',
        'status',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'last_push_at' => 'datetime',
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(MaintenanceCase::class, 'case_id', 'case_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Pengguna::class, 'assigned_to', 'user_id');
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(GitHubRepository::class, 'repository_id', 'repository_id');
    }

    public function pullRequests(): HasMany
    {
        return $this->hasMany(PullRequest::class, 'backlog_id', 'backlog_id');
    }
}
