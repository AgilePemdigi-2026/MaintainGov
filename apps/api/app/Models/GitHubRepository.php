<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GitHubRepository extends Model
{
    protected $table = 'github_repository';

    protected $primaryKey = 'repository_id';

    protected $fillable = [
        'github_repository_id',
        'owner',
        'name',
        'full_name',
        'default_branch',
        'html_url',
        'is_private',
        'last_checked_at',
    ];

    protected $casts = [
        'github_repository_id' => 'integer',
        'is_private' => 'boolean',
        'last_checked_at' => 'datetime',
    ];

    public function backlogs(): HasMany
    {
        return $this->hasMany(MaintenanceBacklog::class, 'repository_id', 'repository_id');
    }

    public function pullRequests(): HasMany
    {
        return $this->hasMany(PullRequest::class, 'repository_id', 'repository_id');
    }
}
