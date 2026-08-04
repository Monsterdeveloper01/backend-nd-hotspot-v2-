<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Voucher extends Model
{
    protected $fillable = [
        'voucher_plan_id',
        'code',
        'customer_phone',
        'price',
        'status',
        'mikrotik_id',
        'used_at',
        'expires_at',
        'mac_address'
    ];

    protected $casts = [
        'used_at' => 'datetime',
        'expires_at' => 'datetime',
        'price' => 'decimal:2',
    ];

    public function plan()
    {
        return $this->belongsTo(VoucherPlan::class, 'voucher_plan_id');
    }
}
