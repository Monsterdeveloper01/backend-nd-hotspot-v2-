<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PointTransaction extends Model
{
    protected $fillable = [
        'point_account_id',
        'phone',
        'type',
        'source_type',
        'source_id',
        'points',
        'balance_before',
        'balance_after',
        'description',
        'metadata',
    ];

    protected $casts = [
        'points' => 'integer',
        'balance_before' => 'integer',
        'balance_after' => 'integer',
        'metadata' => 'array',
    ];

    /**
     * Parent point account.
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(PointAccount::class, 'point_account_id');
    }
}
