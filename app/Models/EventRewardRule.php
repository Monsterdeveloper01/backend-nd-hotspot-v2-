<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * EventRewardRule Model
 * 
 * Phase 2: Automatic Reward System
 * Defines the reward rule associated with an event.
 * 
 * NOTE: Target amount is strictly inherited from events.target_amount.
 * This rule determines WHICH voucher plan is automatically granted when
 * a customer reaches the event's target_amount.
 */
class EventRewardRule extends Model
{
    protected $fillable = [
        'event_id',
        'voucher_plan_id',
        'name',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function voucherPlan()
    {
        return $this->belongsTo(VoucherPlan::class, 'voucher_plan_id');
    }

    public function rewards()
    {
        return $this->hasMany(EventReward::class);
    }
}
