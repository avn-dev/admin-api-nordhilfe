<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Mail\BookingConfirmation;
use Illuminate\Support\Facades\Mail;
use App\Models\Participant;

class SendTableStatisticChangedNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // protected $data;
    protected Participant $participant;

    /**
     * Create a new job instance.
     */
    public function __construct($data, Participant $participant)
    {
        // $this->data = $data;
        $this->participant = $participant;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Mail::to($this->$recipient->email)->send(new BookingConfirmation($this->participant));
    }
}
