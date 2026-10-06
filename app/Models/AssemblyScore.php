<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssemblyScore extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'goal_id',
        'journey_id',
        'step_id',
        'assembly_score',
    ];

    protected $casts = [
        'assembly_score' => 'decimal:6',
    ];

    /**
     * Get the project that owns the assembly score.
     */
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the goal set for this assembly score.
     */
    public function goalSet()
    {
        return $this->belongsTo(GoalSet::class, 'goal_id', 'goal_id');
    }

    /**
     * Get the journey for this assembly score.
     */
    public function journey()
    {
        return $this->belongsTo(Journey::class, 'journey_id', 'journey_id');
    }

    /**
     * Scope to get high-score touchpoints.
     */
    public function scopeHighScore($query, $threshold = 0.75)
    {
        return $query->where('assembly_score', '>=', $threshold);
    }

    /**
     * Scope to get moderate-score touchpoints.
     */
    public function scopeModerateScore($query, $min = 0.45, $max = 0.75)
    {
        return $query->whereBetween('assembly_score', [$min, $max]);
    }

    /**
     * Scope to get low-score touchpoints.
     */
    public function scopeLowScore($query, $threshold = 0.45)
    {
        return $query->where('assembly_score', '<', $threshold);
    }
}
