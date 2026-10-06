<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttributionScore extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'goal_id',
        'journey_id',
        'step_id',
        'model_name',
        'raw_score',
    ];

    protected $casts = [
        'raw_score' => 'decimal:6',
    ];

    /**
     * Get the project that owns the attribution score.
     */
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the goal set for this attribution score.
     */
    public function goalSet()
    {
        return $this->belongsTo(GoalSet::class, 'goal_id', 'goal_id');
    }

    /**
     * Get the journey for this attribution score.
     */
    public function journey()
    {
        return $this->belongsTo(Journey::class, 'journey_id', 'journey_id');
    }

    /**
     * Scope to get scores for a specific model.
     */
    public function scopeForModel($query, $modelName)
    {
        return $query->where('model_name', $modelName);
    }

    /**
     * Scope to get scores for a specific step.
     */
    public function scopeForStep($query, $stepId)
    {
        return $query->where('step_id', $stepId);
    }

    /**
     * Scope to get high-score touchpoints.
     */
    public function scopeHighScore($query, $threshold = 0.75)
    {
        return $query->where('raw_score', '>=', $threshold);
    }
}
