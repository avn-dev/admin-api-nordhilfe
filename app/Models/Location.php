<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Location extends Model
{
    protected $fillable = [
        'name',
        'address',
        'house_number',
        'address_extra',
        'city',
        'postal_code'
    ];

    public function getFullAddressAttribute()
    {
        $extra = $this->address_extra ? "{$this->address_extra}, " : '';

        return "{$this->address} {$this->house_number}, {$extra}{$this->postal_code} {$this->city}";
    }

    public function getFullAddressWithNameAttribute()
    {
        $name = $this->name ? " ({$this->name})" : '';

        return "{$this->full_address}{$name}";
    }

    public function trainingSessions()
    {
        return $this->hasMany(TrainingSession::class);
    }
}
