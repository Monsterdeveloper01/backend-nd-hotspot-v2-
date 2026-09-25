<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * EventTestState Model
 * 
 * Phase 2: Loyalty Test Mode
 * Stores isolated simulation progress metrics for test phones without creating
 * any fake records in the production transactions table or affecting revenue.
 */
class EventTestState extends Model
{
    protected $fillable = [
        'event_id',
        'phone',
        'period_key',
        'simulated_total_purchase',
        'simulated_transaction_count',
        'use_real_mikrotik',
    ];

    protected $casts = [
        'simulated_total_purchase' => 'decimal:2',
        'simulated_transaction_count' => 'integer',
        'use_real_mikrotik' => 'boolean',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }
}
