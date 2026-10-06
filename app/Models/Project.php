<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Project extends Model
{
    protected $fillable = [
        'organization_id',
        'name',
        'industry',
        'timezone',
        'description',
        'goal_type',
        'setup_step',
        'setup_completed',
        'goal_external_id',
        'goal_path',
        'goal_event_type',
        'goal_occurrences',
    ];

    protected $casts = [
        'setup_completed' => 'boolean',
        'setup_step' => 'integer',
        'goal_occurrences' => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function bigQueryConnection(): HasOne
    {
        return $this->hasOne(BigQueryConnection::class);
    }

    public function segments(): HasMany
    {
        return $this->hasMany(Segment::class);
    }

    public function reportGenerators(): HasMany
    {
        return $this->hasMany(ReportGenerator::class);
    }
}
