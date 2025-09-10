{{-- resources/views/emails/booking_confirmed.blade.php --}}
@php
    // Preheader unsichtbar:
@endphp
<span style="display:none!important;visibility:hidden;mso-hide:all;opacity:0;color:transparent;max-height:0;max-width:0;overflow:hidden;">
    Datum, Uhrzeit, Ort & Route.
</span>

@component('mail::message')
# Nordhilfe – Buchungsbestätigung

Hallo {{ $p->first_name }},

deine Buchung ist bestätigt. Hier sind die wichtigsten Infos:

**DEIN TERMIN**  
- **Kurs:** {{ $course->name }}  
- **Datum:** {{ $ts->getFormattedSessionDate() }}  
- **Zeit:** {{ $ts->getFormattedStartTime() }}–{{ $ts->getFormattedEndTime() }} Uhr @if(!empty($d['duration_minutes'])) (Dauer {{ $d['duration_minutes'] }} Min) @endif  
- **Ort:** {{ $loc->name }}, {{ $loc->full_address }}  
- **Bestellnummer:** {{ $d['order_number'] }}

@component('mail::button', ['url' => $d['map_url']])
Route in Google Maps öffnen
@endcomponent

**ZAHLUNG**  
Status: {{ $d['payment_status'] }} • Methode: {{ $d['payment_method'] }} • Betrag: {{ number_format($d['total_price'], 2, ',', '.') }} €

**Kurz & wichtig:**  
Falls du **Sehtest** und/oder **Passbilder** gebucht hast, komm bitte **30 Minuten früher**, damit wir alles in Ruhe erledigen und pünktlich starten können.

**Deine Teilnahme ist garantiert:**  
Fällt der Kurs wider Erwarten aus, buchen wir dich automatisch & kostenfrei auf den nächsten Termin – ohne dass du dich neu anmelden musst.

**WAS DU MITBRINGEN SOLLTEST**
- Amtliches Ausweisdokument  
- Sehhilfe (Brille/Kontaktlinsen)  
- Bequeme Kleidung, etwas zu trinken

Wir freuen uns auf dich!  
Bis {{ $ts->getFormattedSessionDate() }}, {{ $ts->getFormattedStartTime() }} Uhr – wir sehen uns im Kurs.

**SUPPORT**  
E-Mail: info@nordhilfe-hamburg.de  
Telefon: +49 152 51765929

Dein Nordhilfe-Team
@endcomponent
