<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Participant extends Model
{
    protected $fillable = [
        'attended',
        'first_name',
        'last_name',
        'birth_date',
        'email',
        'phone',
        'training_session_id',
        'vision_test',
        'passport_photos'
    ];

    public function getFullNameAttribute()
    {
        return "{$this->first_name} {$this->last_name}";
    }

    public function scopeConfirmed($query)
    {
        return $query->where('attendment_status', 'confirmed');
    }

    public function trainingSession()
    {
        return $this->belongsTo(TrainingSession::class, 'training_session_id');
    }

    public function getFormattedBirthDateAttribute()
    {
        return $this->birth_date ? Carbon::parse($this->birth_date)->format('d.m.Y') : null;
    }

    public function course()
    {
        return $this->trainingSession ? $this->trainingSession->course : null;
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
}
