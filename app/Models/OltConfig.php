<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OltConfig extends Model
{
    protected $fillable = [
        'name',
        'ip_address',
        'port',
        'username',
        'password',
        'snmp_community',
        'type',
        'is_active'
    ];

    public function nodes()
    {
        return $this->hasMany(OnuNode::class, 'olt_id');
    }
}
