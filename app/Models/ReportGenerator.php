<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportGenerator extends Model
{
    public const STATUS_INIT = 'init';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'report_id',
        'request_object',
        'request_hash',
        'status',
        'progress_percentage',
        'response_object',
        'user_id',
        'project_id',
    ];

    protected $casts = [
        'request_object' => 'array',
        'response_object' => 'array',
        'progress_percentage' => 'integer',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
