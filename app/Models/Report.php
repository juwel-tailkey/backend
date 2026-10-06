<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Report extends Model
{
    protected $fillable = [
        'title',
        'slug',
        'description',
        'sample_request_object',
        'sample_response_object',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sample_response_object' => 'array',
    ];

    public function reportGenerators(): HasMany
    {
        return $this->hasMany(ReportGenerator::class);
    }
}
