<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Voucher extends Model
{
    protected $fillable = [
        'voucher_plan_id',
        'code',
        'customer_phone',
        'source',
        'is_test',
        'price',
        'status',
        'mikrotik_id',
        'used_at',
        'expires_at',
        'mac_address'
    ];

    protected $casts = [
        'is_test' => 'boolean',
        'used_at' => 'datetime',
        'expires_at' => 'datetime',
        'price' => 'decimal:2',
    ];

    public function plan()
    {
        return $this->belongsTo(VoucherPlan::class, 'voucher_plan_id');
    }

    public function transaction()
    {
        return $this->hasOne(Transaction::class, 'voucher_id');
    }

    public function eventReward()
    {
        return $this->hasOne(EventReward::class, 'voucher_id');
    }
}
