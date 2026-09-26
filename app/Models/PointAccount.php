<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PointAccount extends Model
{
    protected $fillable = [
        'phone',
        'balance',
        'lifetime_earned',
        'lifetime_spent',
        'status',
    ];

    protected $casts = [
        'balance' => 'integer',
        'lifetime_earned' => 'integer',
        'lifetime_spent' => 'integer',
    ];

    /**
     * Ledger history for this account.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(PointTransaction::class, 'point_account_id')->orderBy('created_at', 'desc');
    }

    /**
     * Masked phone for privacy-preserving analytics display.
     * e.g. 628123456789 -> 62812****789
     */
    public function getMaskedPhoneAttribute(): string
    {
        $phone = $this->phone;
        if (strlen($phone) <= 6) {
            return $phone;
        }

        $start = substr($phone, 0, 5);
        $end = substr($phone, -3);
        return $start . '****' . $end;
    }
}
