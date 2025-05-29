<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;


class TrainingSession extends Model
{
    protected $fillable = [
        'course_id',
        'location_id',
        'session_date',
        'start_time',
        'end_time',
        'max_participants',
        'is_cancelled'
    ];

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function participants()
    {
        return $this->hasMany(Participant::class);
    }

    public function getFormattedSessionDate(): string
    {
        return Carbon::parse($this->session_date)->format('d.m.Y');
    }

    public function getFormattedStartTime(): string
    {
        return Carbon::parse($this->start_time)->format('H:i');
    }

    public function getFormattedEndTime(): string
    {
        return Carbon::parse($this->end_time)->format('H:i');
    }

    public function getDescriptionAttribute()
    {
        return "{$this->course->name} in {$this->location->full_address_with_name} am {$this->getFormattedSessionDate()} von {$this->getFormattedStartTime()} bis {$this->getFormattedEndTime()}";
    }

    public function getShortDescriptionAttribute()
    {
        return "{$this->course->name} am {$this->getFormattedSessionDate()} von {$this->getFormattedStartTime()} bis {$this->getFormattedEndTime()}";
    }
}
