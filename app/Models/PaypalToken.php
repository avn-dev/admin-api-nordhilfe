<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaypalToken extends Model
{
    protected $fillable = ['token', 'used', 'payload'];

    protected $casts = [
        'used' => 'boolean',
        'payload' => 'array',
    ];
}
