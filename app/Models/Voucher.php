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

    /**
     * Check if this voucher was purchased via ND-Points (Redeem or Auto-Voucher).
     */
    public function isPointVoucher(): bool
    {
        if ($this->relationLoaded('transaction') && $this->transaction) {
            $method = $this->transaction->payment_method ?? $this->transaction->payment_type ?? '';
            return in_array($method, ['nd_points', 'nd_points_autodebit'])
                || str_starts_with($this->transaction->external_id ?? '', 'PTS-');
        }

        return $this->transaction()
            ->where(function ($q) {
                $q->whereIn('payment_method', ['nd_points', 'nd_points_autodebit'])
                  ->orWhere('external_id', 'LIKE', 'PTS-%');
            })
            ->exists();
    }
}
