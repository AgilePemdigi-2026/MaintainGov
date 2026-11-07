<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GitHubWebhookDelivery extends Model
{
    protected $table = 'github_webhook_delivery';

    protected $primaryKey = 'webhook_delivery_id';

    protected $fillable = [
        'github_delivery_id',
        'event',
        'action',
        'repository_full_name',
        'ref',
        'after_sha',
        'status',
        'backlog_id',
        'error_code',
        'processed_at',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
    ];

    public function backlog(): BelongsTo
    {
        return $this->belongsTo(MaintenanceBacklog::class, 'backlog_id', 'backlog_id');
    }
}
