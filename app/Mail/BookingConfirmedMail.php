<?php
namespace App\Mail;

use App\Models\Participant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class BookingConfirmedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Participant $participant,
        public array $data // order_number, payment_status, payment_method, total_price, map_url, duration_minutes
    ) {}

    public function envelope()
    {
        $ts = $this->participant->trainingSession;
        $subject = sprintf(
            'Buchung bestätigt – %s, %s Uhr',
            $ts->getFormattedSessionDate(),
            $ts->getFormattedStartTime()
        );

        return new \Illuminate\Mail\Mailables\Envelope(subject: $subject);
    }

    public function content()
    {
        return new \Illuminate\Mail\Mailables\Content(
            markdown: 'emails.booking_confirmed',
            with: [
                'p' => $this->participant,
                'ts' => $this->participant->trainingSession,
                'course' => $this->participant->trainingSession->course,
                'loc' => $this->participant->trainingSession->location,
                'd' => $this->data,
            ],
        );
    }
}
