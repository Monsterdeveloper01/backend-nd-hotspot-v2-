<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RadiusClient extends Model
{
    protected $fillable = ['name', 'ip_address', 'shared_secret'];

    protected $casts = [
        'ip_address' => 'encrypted',
        'shared_secret' => 'encrypted',
    ];
}
