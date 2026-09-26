<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PointRule extends Model
{
    protected $fillable = [
        'name',
        'description',
        'type',
        'calculation_type',
        'value',
        'unit_amount',
        'min_purchase_amount',
        'max_purchase_amount',
        'event_id',
        'priority',
        'is_active',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'value' => 'float',
        'unit_amount' => 'float',
        'min_purchase_amount' => 'float',
        'max_purchase_amount' => 'float',
        'priority' => 'integer',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    /**
     * Check if the rule is currently active and within valid date window.
     */
    public function isValidForExecution(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        $now = now();
        if ($this->starts_at && $now->lt($this->starts_at)) {
            return false;
        }

        if ($this->ends_at && $now->gt($this->ends_at)) {
            return false;
        }

        return true;
    }

    /**
     * Calculate points awarded by this rule for a given purchase amount.
     * 
     * @param float $amount The transaction amount
     * @param int $basePoints Already calculated points before multiplier
     * @return int Points to award
     */
    public function calculatePointsForAmount(float $amount, int $basePoints = 0): int
    {
        // Check minimum purchase
        if ($this->min_purchase_amount !== null && $amount < $this->min_purchase_amount) {
            return 0;
        }

        // Check maximum purchase limit
        if ($this->max_purchase_amount !== null && $amount > $this->max_purchase_amount) {
            return 0;
        }

        switch ($this->calculation_type) {
            case 'per_unit':
            case 'ratio':
                // e.g. Rp 1.000 = 10 ND-Point
                $unit = ($this->unit_amount && $this->unit_amount > 0) ? $this->unit_amount : 1000.00;
                $multiplier = (int) floor($amount / $unit);
                return (int) round($multiplier * $this->value);

            case 'fixed_bonus':
            case 'flat':
                return (int) round($this->value);

            case 'percentage':
                return (int) floor(($amount * $this->value) / 100);

            case 'multiplier':
                if ($basePoints > 0) {
                    return (int) round($basePoints * ($this->value - 1));
                }
                return 0;

            default:
                $unit = ($this->unit_amount && $this->unit_amount > 0) ? $this->unit_amount : 1000.00;
                return (int) round(floor($amount / $unit) * $this->value);
        }
    }
}
