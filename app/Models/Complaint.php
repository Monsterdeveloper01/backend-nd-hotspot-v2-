<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Complaint extends Model
{
    protected $fillable = [
        'phone_number',
        'raw_message',
        'ai_summary',
        'status',
    ];
}
