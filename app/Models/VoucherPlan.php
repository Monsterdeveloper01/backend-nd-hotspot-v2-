<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoucherPlan extends Model
{
    protected $fillable = [
        'name',
        'duration',
        'upload_limit',
        'download_limit',
        'speed_limit',
        'shared_users',
        'price',
        'mikrotik_profile',
        'is_gaming'
    ];

    protected $casts = [
        'is_gaming' => 'boolean',
    ];
}
