<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JourneyStrength extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'goal_id',
        'journey_id',
        'strength_score',
        'goal_bonus',
        'diversity',
        'length_fit',
        'balance',
        'context',
        'strength_tier',
    ];

    protected $casts = [
        'strength_score' => 'decimal:6',
        'goal_bonus' => 'decimal:6',
        'diversity' => 'decimal:6',
        'length_fit' => 'decimal:6',
        'balance' => 'decimal:6',
        'context' => 'decimal:6',
    ];

    /**
     * Get the project that owns the journey strength.
     */
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the goal set for this journey strength.
     */
    public function goalSet()
    {
        return $this->belongsTo(GoalSet::class, 'goal_id', 'goal_id');
    }

    /**
     * Get the journey for this strength record.
     */
    public function journey()
    {
        return $this->belongsTo(Journey::class, 'journey_id', 'journey_id');
    }

    /**
     * Scope to get high-strength journeys.
     */
    public function scopeHighStrength($query, $threshold = 0.75)
    {
        return $query->where('strength_score', '>=', $threshold);
    }

    /**
     * Scope to get moderate-strength journeys.
     */
    public function scopeModerateStrength($query, $min = 0.45, $max = 0.75)
    {
        return $query->whereBetween('strength_score', [$min, $max]);
    }

    /**
     * Scope to get low-strength journeys.
     */
    public function scopeLowStrength($query, $threshold = 0.45)
    {
        return $query->where('strength_score', '<', $threshold);
    }

    /**
     * Scope to get journeys by strength tier.
     */
    public function scopeByTier($query, $tier)
    {
        return $query->where('strength_tier', $tier);
    }

    /**
     * Get strength tier based on score.
     */
    public function getCalculatedTierAttribute()
    {
        if ($this->strength_score >= 0.75) {
            return 'high_strength';
        }

        if ($this->strength_score >= 0.45) {
            return 'moderate_strength';
        }

        return 'low_strength';
    }

    /**
     * Check if journey is high strength.
     */
    public function isHighStrength()
    {
        return $this->strength_score >= 0.75;
    }

    /**
     * Check if journey is moderate strength.
     */
    public function isModerateStrength()
    {
        return $this->strength_score >= 0.45 && $this->strength_score < 0.75;
    }

    /**
     * Check if journey is low strength.
     */
    public function isLowStrength()
    {
        return $this->strength_score < 0.45;
    }
}
