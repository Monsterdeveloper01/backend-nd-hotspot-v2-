<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;

class PointAccount extends Model
{
    protected $fillable = [
        'phone',
        'balance',
        'lifetime_earned',
        'lifetime_spent',
        'status',
        'pin_hash',
        'failed_pin_attempts',
        'locked_until',
    ];

    protected $hidden = [
        'pin_hash',
    ];

    protected $casts = [
        'balance' => 'integer',
        'lifetime_earned' => 'integer',
        'lifetime_spent' => 'integer',
        'failed_pin_attempts' => 'integer',
        'locked_until' => 'datetime',
    ];

    /**
     * Check if account has a PIN set.
     */
    public function hasPin(): bool
    {
        return !empty($this->pin_hash);
    }

    /**
     * Check if account is currently locked due to too many failed attempts.
     */
    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    /**
     * Get remaining lock seconds.
     */
    public function getLockSecondsRemaining(): int
    {
        if (!$this->isLocked()) {
            return 0;
        }
        return (int) max(0, now()->diffInSeconds($this->locked_until));
    }

    /**
     * Set a new PIN (hashes with Bcrypt and resets failed attempts).
     */
    public function setPin(string $pin): void
    {
        $this->pin_hash = Hash::make($pin);
        $this->failed_pin_attempts = 0;
        $this->locked_until = null;
        $this->save();
    }

    /**
     * Verify PIN against hash with brute force throttling.
     */
    public function verifyPin(string $pin): bool
    {
        if ($this->isLocked()) {
            return false;
        }

        if (empty($this->pin_hash)) {
            return false;
        }

        if (Hash::check($pin, $this->pin_hash)) {
            // Success: reset failed attempts
            if ($this->failed_pin_attempts > 0 || $this->locked_until !== null) {
                $this->failed_pin_attempts = 0;
                $this->locked_until = null;
                $this->save();
            }
            return true;
        }

        // Failed attempt: increment and check lock threshold
        $this->failed_pin_attempts += 1;
        if ($this->failed_pin_attempts >= 5) {
            // Lock for 15 minutes
            $this->locked_until = now()->addMinutes(15);
        }
        $this->save();

        return false;
    }

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
