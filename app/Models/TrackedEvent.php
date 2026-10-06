<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TrackedEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'event_key',
        'event_name',
        'event_category',
        'parameters',
        'is_active',
    ];

    protected $casts = [
        'parameters' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Get the project that owns the tracked event.
     */
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Scope to get only active events.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get events by category.
     */
    public function scopeByCategory($query, $category)
    {
        return $query->where('event_category', $category);
    }

    /**
     * Scope to get intent events.
     */
    public function scopeIntent($query)
    {
        return $query->where('event_category', 'intent');
    }

    /**
     * Scope to get conversion events.
     */
    public function scopeConversion($query)
    {
        return $query->where('event_category', 'conversion');
    }
}
