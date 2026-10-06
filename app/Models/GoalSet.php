<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GoalSet extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'goal_id',
        'goal_name',
        'goal_event_name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get the project that owns the goal set.
     */
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the journeys for this goal.
     */
    public function journeys()
    {
        return $this->hasMany(Journey::class, 'goal_id', 'goal_id');
    }

    /**
     * Get the attribution scores for this goal.
     */
    public function attributionScores()
    {
        return $this->hasMany(AttributionScore::class, 'goal_id', 'goal_id');
    }

    /**
     * Get the assembly scores for this goal.
     */
    public function assemblyScores()
    {
        return $this->hasMany(AssemblyScore::class, 'goal_id', 'goal_id');
    }

    /**
     * Get the ATB values for this goal.
     */
    public function atbVals()
    {
        return $this->hasMany(AtbVal::class, 'goal_id', 'goal_id');
    }

    /**
     * Get the journey strength records for this goal.
     */
    public function journeyStrengths()
    {
        return $this->hasMany(JourneyStrength::class, 'goal_id', 'goal_id');
    }

    /**
     * Scope to get only active goals.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
