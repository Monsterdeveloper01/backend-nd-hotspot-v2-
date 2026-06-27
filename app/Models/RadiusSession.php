<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RadiusSession extends Model
{
    protected $appends = ['bytes_in_human', 'bytes_out_human', 'session_duration'];

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

    public function getBytesInHumanAttribute(): string
    {
        return $this->formatBytes($this->bytes_in ?? 0);
    }

    public function getBytesOutHumanAttribute(): string
    {
        return $this->formatBytes($this->bytes_out ?? 0);
    }

    public function getSessionDurationAttribute(): string
    {
        $seconds = $this->session_time ?? 0;
        if ($seconds < 60) return $seconds . ' detik';
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        if ($hours > 0) return $hours . ' jam ' . $minutes . ' menit';
        return $minutes . ' menit';
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
        return $bytes . ' B';
    }
}

