<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = [
        'external_id', 'voucher_plan_id', 'voucher_id', 'customer_phone',
        'amount', 'status', 'payment_method', 'payment_url', 'snap_token'
    ];

    public function plan()
    {
        return $this->belongsTo(VoucherPlan::class, 'voucher_plan_id');
    }

    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }
}
