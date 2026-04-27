<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OnuNode extends Model
{
    protected $fillable = [
        'olt_id',
        'onu_index',
        'serial_number',
        'alias',
        'ip_address',
        'last_signal',
        'last_temp',
        'client_count',
        'last_check',
        'status'
    ];

    public function olt()
    {
        return $this->belongsTo(OltConfig::class, 'olt_id');
    }
}
