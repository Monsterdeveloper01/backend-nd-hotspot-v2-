<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * EventParticipant Model
 * 
 * Phase 1: Event System Analytics
 * Monthly aggregation of customer purchases within an event period.
 * Each record = one customer + one month (period_key).
 */
class EventParticipant extends Model
{
    protected $fillable = [
        'event_id',
        'phone',
        'period_key',
        'total_purchase',
        'transaction_count',
        'first_transaction_at',
        'last_transaction_at',
    ];

    protected $casts = [
        'total_purchase' => 'decimal:2',
        'transaction_count' => 'integer',
        'first_transaction_at' => 'datetime',
        'last_transaction_at' => 'datetime',
    ];

    /**
     * Get the event this participant belongs to.
     */
    public function event()
    {
        return $this->belongsTo(Event::class);
    }
}
