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

    public function plan()
    {
        return $this->belongsTo(VoucherPlan::class, 'voucher_plan_id');
    }
}
