<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'whatsapp',
        'billing_amount',
        'due_date',
        'status_bayar',
        'is_isolated'
    ];

    protected $casts = [
        'due_date' => 'date',
        'billing_amount' => 'decimal:2',
        'is_isolated' => 'boolean'
    ];
}
