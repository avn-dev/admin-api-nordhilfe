<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Course extends Model
{
    protected $fillable = [
        'name',
        'description',
        'base_price'
    ];

    public function trainingSessions()
    {
        return $this->hasMany(TrainingSession::class);
    }
}
