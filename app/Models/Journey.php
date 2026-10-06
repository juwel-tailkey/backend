<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Journey extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'goal_id',
        'device_id',
        'journey_id',
        'source_step',
        'step_sequence',
        'goal_achieved',
        'journey_ts',
        'goal_completed_ts',
    ];

    protected $casts = [
        'goal_achieved' => 'boolean',
        'journey_ts' => 'datetime',
        'goal_completed_ts' => 'datetime',
    ];

    /**
     * Get the project that owns the journey.
     */
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the goal set for this journey.
     */
    public function goalSet()
    {
        return $this->belongsTo(GoalSet::class, 'goal_id', 'goal_id');
    }

    /**
     * Get the attribution scores for this journey.
     */
    public function attributionScores()
    {
        return $this->hasMany(AttributionScore::class, 'journey_id', 'journey_id');
    }

    /**
     * Get the assembly score for this journey.
     */
    public function assemblyScores()
    {
        return $this->hasMany(AssemblyScore::class, 'journey_id', 'journey_id');
    }

    /**
     * Get the ATB value for this journey.
     */
    public function atbVals()
    {
        return $this->hasMany(AtbVal::class, 'journey_id', 'journey_id');
    }

    /**
     * Get the journey strength for this journey.
     */
    public function journeyStrength()
    {
        return $this->hasOne(JourneyStrength::class, 'journey_id', 'journey_id');
    }

    /**
     * Scope to get only converting journeys.
     */
    public function scopeConverting($query)
    {
        return $query->where('goal_achieved', true);
    }

    /**
     * Scope to get only non-converting journeys.
     */
    public function scopeNonConverting($query)
    {
        return $query->where('goal_achieved', false);
    }

    /**
     * Scope to get journeys for a specific device.
     */
    public function scopeForDevice($query, $deviceId)
    {
        return $query->where('device_id', $deviceId);
    }

    /**
     * Get consideration cycle length in hours.
     */
    public function getConsiderationHoursAttribute()
    {
        if (!$this->goal_achieved || !$this->goal_completed_ts) {
            return null;
        }

        return $this->journey_ts->diffInHours($this->goal_completed_ts);
    }

    /**
     * Get consideration cycle tier.
     */
    public function getConsiderationTierAttribute()
    {
        $hours = $this->consideration_hours;

        if ($hours === null) {
            return null;
        }

        if ($hours <= 24) {
            return 'fast_decider';
        }

        if ($hours <= 168) {
            return 'moderate_decider';
        }

        return 'slow_decider';
    }
}
