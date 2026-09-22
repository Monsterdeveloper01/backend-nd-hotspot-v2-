<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = [
        'external_id', 'voucher_plan_id', 'voucher_id', 'customer_phone',
        'amount', 'status', 'payment_method', 'payment_url', 'qr_string', 'snap_token'
    ];

    public function plan()
    {
        return $this->belongsTo(VoucherPlan::class, 'voucher_plan_id');
    }

    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_phone', 'whatsapp');
    }

    /**
     * The "booted" method of the model.
     * Ensures 100% real-time tracking for successful voucher transactions.
     */
    protected static function booted()
    {
        static::saved(function ($transaction) {
            if (($transaction->status === 'success') && str_starts_with($transaction->external_id ?? '', 'ND-') && !empty($transaction->customer_phone)) {
                \App\Services\EventAnalyticsService::processTransaction($transaction);
            }
        });
    }
}
