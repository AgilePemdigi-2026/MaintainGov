<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaintenanceCase extends Model
{
    protected $table = 'maintenance_case';

    protected $primaryKey = 'case_id';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'judul_issue',
        'kategori',
        'modul',
        'fitur',
        'severity',
        'priority_score',
        'ringkasan_masalah',
        'recommendation',
        'status_validasi',
        'validated_at',
        'created_at',
    ];

    protected $casts = [
        'priority_score' => 'float',
        'validated_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function backlog(): HasMany
    {
        return $this->hasMany(MaintenanceBacklog::class, 'case_id', 'case_id');
    }
}
