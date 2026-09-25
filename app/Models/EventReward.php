<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * EventReward Model
 * 
 * Phase 2: Automatic Reward System
 * Tracks individual rewards granted to customers when target is reached.
 * 
 * Statuses:
 * - processing: Reward record created atomically, proceeding with external side effects (MikroTik)
 * - issued: Voucher created in DB AND verified in MikroTik (usable)
 * - used: Voucher has been logged in/used by customer
 * - expired: 5 days passed since issued_at
 * - cancelled: Cancelled by admin
 * - failed: Voucher generation or MikroTik sync failed
 */
class EventReward extends Model
{
    protected $fillable = [
        'event_id',
        'event_reward_rule_id',
        'phone',
        'period_key',
        'reward_type',
        'reward_value',
        'status',
        'voucher_id',
        'granted_at',
        'issued_at',
        'expires_at',
        'error_message',
    ];

    protected $casts = [
        'granted_at' => 'datetime',
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function rule()
    {
        return $this->belongsTo(EventRewardRule::class, 'event_reward_rule_id');
    }

    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }
}
