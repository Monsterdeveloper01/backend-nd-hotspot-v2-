<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RadiusSession extends Model
{
    protected $fillable = [
        'session_id',
        'username',
        'nas_ip',
        'nas_port',
        'mac_address',
        'framed_ip',
        'started_at',
        'stopped_at',
        'bytes_in',
        'bytes_out',
        'session_time',
        'terminate_cause',
        'is_active',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'stopped_at' => 'datetime',
        'is_active' => 'boolean',
        'bytes_in' => 'integer',
        'bytes_out' => 'integer',
        'session_time' => 'integer',
    ];

    /**
     * Get currently active sessions for a given username.
     */
    public static function activeSessionCount($username)
    {
        return self::where('username', $username)->where('is_active', true)->count();
    }
}
