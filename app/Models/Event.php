<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Event Model
 * 
 * Phase 1: Event System Analytics
 * Defines an analytics event/period for tracking customer purchase patterns.
 * Internal admin-only. No reward logic.
 */
class Event extends Model
{
    protected $fillable = [
        'name',
        'description',
        'target_amount',
        'status',
        'last_synced_at',
        'sync_stats',
    ];

    protected $casts = [
        'target_amount' => 'decimal:2',
        'last_synced_at' => 'datetime',
        'sync_stats' => 'array',
    ];

    /**
     * Get all participants for this event.
     */
    public function participants()
    {
        return $this->hasMany(EventParticipant::class);
    }
}
