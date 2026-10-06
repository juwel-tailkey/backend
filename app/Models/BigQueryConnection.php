<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BigQueryConnection extends Model
{
    protected $fillable = [
        'project_id',
        'gcp_project_id',
        'dataset_id',
        'location',
        'service_account_path',
        'service_account_filename',
        'is_connected',
        'selected_report',
        'connected_at',
    ];

    protected $casts = [
        'is_connected' => 'boolean',
        'connected_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
