<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AtbVal extends Model
{
    use HasFactory;

    protected $table = 'atb_val';

    protected $fillable = [
        'project_id',
        'goal_id',
        'journey_id',
        'step_id',
        'atb_val',
        'removal_effect_correction',
    ];

    protected $casts = [
        'atb_val' => 'decimal:6',
        'removal_effect_correction' => 'decimal:6',
    ];

    /**
     * Get the project that owns the ATB value.
     */
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the goal set for this ATB value.
     */
    public function goalSet()
    {
        return $this->belongsTo(GoalSet::class, 'goal_id', 'goal_id');
    }

    /**
     * Get the journey for this ATB value.
     */
    public function journey()
    {
        return $this->belongsTo(Journey::class, 'journey_id', 'journey_id');
    }

    /**
     * Scope to get high-impact touchpoints.
     */
    public function scopeHighImpact($query, $threshold = 0.5)
    {
        return $query->where('atb_val', '>=', $threshold);
    }

    /**
     * Scope to get moderate-impact touchpoints.
     */
    public function scopeModerateImpact($query, $min = 0.2, $max = 0.5)
    {
        return $query->whereBetween('atb_val', [$min, $max]);
    }

    /**
     * Scope to get low-impact touchpoints.
     */
    public function scopeLowImpact($query, $threshold = 0.2)
    {
        return $query->where('atb_val', '<', $threshold);
    }

    /**
     * Get impact category.
     */
    public function getImpactCategoryAttribute()
    {
        if ($this->atb_val >= 0.5) {
            return 'high';
        }

        if ($this->atb_val >= 0.2) {
            return 'medium';
        }

        return 'low';
    }
}
