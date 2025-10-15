<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Course extends Model
{
    protected $fillable = [
        'name',
        'description',
        'base_price',
        'discounted',
        'discount_price'
    ];

    public function trainingSessions()
    {
        return $this->hasMany(TrainingSession::class);
    }
}
