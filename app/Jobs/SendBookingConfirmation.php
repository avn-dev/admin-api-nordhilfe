<?php

namespace App\Jobs;

use App\Mail\BookingConfirmedMail;
use App\Models\Participant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Carbon\Carbon;

class SendBookingConfirmation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public $backoff = [60, 120, 300, 600];

    public function __construct(public Participant $participant, public array $paymentInfo) {}

    public function middleware()
    {
        // z.B. max 30 Mails/Minute
        return [new RateLimited('email-confirmations')];
    }

    public function handle(): void
    {
        $p = $this->participant->load(['trainingSession.course', 'trainingSession.location', 'payments']);

        $ts = $p->trainingSession;
        $start = Carbon::parse($ts->start_time);
        $end = Carbon::parse($ts->end_time);
        $duration = $end->diffInMinutes($start);

        // Maps-Link bauen (Route zum Ziel)
        $dest = urlencode($ts->location->full_address_with_name);
        $mapUrl = "https://www.google.com/maps/dir/?api=1&destination={$dest}";

        // Order-Nummer: hübsch formatiert, stabil pro Payment
        $orderNumber = $this->paymentInfo['order_number']
            ?? ('BK-' . now()->format('Ymd') . '-' . str_pad((string)($this->paymentInfo['payment_id'] ?? 0), 6, '0', STR_PAD_LEFT));

        $data = [
            'order_number'    => $orderNumber,
            'payment_status'  => $this->paymentInfo['status'] ?? 'unpaid',
            'payment_method'  => $this->paymentInfo['method'] ?? 'unknown',
            'total_price'     => (float)($this->paymentInfo['amount'] ?? 0.0),
            'map_url'         => $mapUrl,
            'duration_minutes'=> $duration,
        ];

        Mail::to($p->email)
            ->bcc("info@nordhilfe-hamburg.de")
            ->queue(new BookingConfirmedMail($p, $data));

        // Optional: Doppelversand verhindern – Flag setzen
        $p->forceFill(['booking_confirmation_sent_at' => now()])->saveQuietly();
    }
}
