<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Announcement extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'type',
        'message',
        'start_time',
        'end_time',
        'expires_at',
        'is_active',
        'dismissible',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time'   => 'datetime',
        'expires_at' => 'datetime',
        'is_active'  => 'boolean',
        'dismissible'=> 'boolean',
    ];

    /**
     * Scope active and not expired
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', Carbon::now());
            });
    }

    /**
     * Purge all expired announcements automatically
     */
    public static function purgeExpired(): int
    {
        return static::whereNotNull('expires_at')
            ->where('expires_at', '<=', Carbon::now())
            ->delete();
    }
}
