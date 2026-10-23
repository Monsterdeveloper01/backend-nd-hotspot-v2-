<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SystemIncident extends Model
{
    use HasFactory;

    protected $table = 'system_incidents';

    protected $fillable = [
        'category',
        'severity',
        'title',
        'message',
        'technical_details',
        'payload',
        'action_type',
        'status',
        'resolved_at',
        'resolved_by',
    ];

    protected $casts = [
        'payload'     => 'array',
        'resolved_at' => 'datetime',
    ];

    public function scopeUnresolved($query)
    {
        return $query->where('status', 'unresolved');
    }

    public function scopeCritical($query)
    {
        return $query->where('severity', 'critical');
    }

    public function scopeCategory($query, $category)
    {
        return $query->where('category', $category);
    }
}