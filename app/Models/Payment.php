<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'participant_id',
        'method',
        'status',
        'amount',
        'currency',
        'external_id',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function participant()
    {
        return $this->belongsTo(Participant::class);
    }
}