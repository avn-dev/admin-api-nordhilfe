<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Models\Participant;

class BookingConfirmation extends Mailable
{
    use Queueable, SerializesModels;

    // private $changedPanels = [];
    private Participant $participant;

    /**
     * Create a new message instance.
     */
    public function __construct(Participant $participant)
    {
        // $this->changedPanels = $changedPanels;
        $this->participant = $participant;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Buchungsbestätigung',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.transactional',
            with: [
                // 'panels' => $this->changedPanels,
                'participant' => $this->participant,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
