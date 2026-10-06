<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChurnScore extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'device_id',
        'churn_probability',
        'risk_band',
        'scored_at',
        'model_version',
        'features',
    ];

    protected $casts = [
        'churn_probability' => 'decimal:6',
        'scored_at' => 'datetime',
        'features' => 'array',
    ];

    /**
     * Get the project that owns the churn score.
     */
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Scope to get high-risk devices.
     */
    public function scopeHighRisk($query)
    {
        return $query->where('risk_band', 'high')->orWhere('churn_probability', '>=', 0.7);
    }

    /**
     * Scope to get medium-risk devices.
     */
    public function scopeMediumRisk($query)
    {
        return $query->where('risk_band', 'medium')->orWhereBetween('churn_probability', [0.4, 0.7]);
    }

    /**
     * Scope to get low-risk devices.
     */
    public function scopeLowRisk($query)
    {
        return $query->where('risk_band', 'low')->orWhere('churn_probability', '<', 0.4);
    }

    /**
     * Scope to get scores by risk band.
     */
    public function scopeByRiskBand($query, $band)
    {
        return $query->where('risk_band', $band);
    }

    /**
     * Get risk band based on probability.
     */
    public function getCalculatedRiskBandAttribute()
    {
        if ($this->churn_probability >= 0.7) {
            return 'high';
        }

        if ($this->churn_probability >= 0.4) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Check if device is high risk.
     */
    public function isHighRisk()
    {
        return $this->churn_probability >= 0.7;
    }

    /**
     * Check if device is medium risk.
     */
    public function isMediumRisk()
    {
        return $this->churn_probability >= 0.4 && $this->churn_probability < 0.7;
    }

    /**
     * Check if device is low risk.
     */
    public function isLowRisk()
    {
        return $this->churn_probability < 0.4;
    }
}
