<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserSegment extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'device_id',
        'segment_family',
        'segment_type',
        'segment_key',
        'segment_data',
        'assigned_at',
        'expires_at',
    ];

    protected $casts = [
        'segment_data' => 'array',
        'assigned_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Get the project that owns the user segment.
     */
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Scope to get active segments (not expired).
     */
    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }

    /**
     * Scope to get expired segments.
     */
    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<=', now());
    }

    /**
     * Scope to get segments by family.
     */
    public function scopeByFamily($query, $family)
    {
        return $query->where('segment_family', $family);
    }

    /**
     * Scope to get segments by type.
     */
    public function scopeByType($query, $type)
    {
        return $query->where('segment_type', $type);
    }

    /**
     * Scope to get segments for a specific device.
     */
    public function scopeForDevice($query, $deviceId)
    {
        return $query->where('device_id', $deviceId);
    }

    /**
     * Scope to get general segments.
     */
    public function scopeGeneral($query)
    {
        return $query->where('segment_family', 'general');
    }

    /**
     * Scope to get attribution segments.
     */
    public function scopeAttribution($query)
    {
        return $query->where('segment_family', 'attribution');
    }

    /**
     * Scope to get churn segments.
     */
    public function scopeChurn($query)
    {
        return $query->where('segment_family', 'churn');
    }

    /**
     * Check if segment is active.
     */
    public function isActive()
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    /**
     * Check if segment is expired.
     */
    public function isExpired()
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
