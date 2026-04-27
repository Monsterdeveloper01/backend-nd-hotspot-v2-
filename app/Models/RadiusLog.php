<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RadiusLog extends Model
{
    protected $fillable = ['username', 'client_ip', 'type', 'status', 'message'];
}
