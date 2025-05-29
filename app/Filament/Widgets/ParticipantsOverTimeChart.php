<?php
namespace App\Filament\Widgets;

use App\Models\Participant;
use Filament\Widgets\LineChartWidget;
use Illuminate\Support\Carbon;

class ParticipantsOverTimeChart extends LineChartWidget
{
    protected static ?string $heading = 'Teilnehmer in Schulungen über Zeit';

    protected function getData(): array
    {
        $attendedData = Participant::whereHas('trainingSession')
            ->where('attended', true)
            ->with('trainingSession')
            ->get()
            ->groupBy(fn($p) => Carbon::parse($p->trainingSession->session_date)->format('Y-m'))
            ->map->count();

        $notAttendedData = Participant::whereHas('trainingSession')
            ->where('attended', false)
            ->with('trainingSession')
            ->get()
            ->groupBy(fn($p) => Carbon::parse($p->trainingSession->session_date)->format('Y-m'))
            ->map->count();

        $labels = collect($attendedData->keys())
            ->merge($notAttendedData->keys())
            ->unique()
            ->sort()
            ->values();

        return [
            'datasets' => [
                [
                    'label' => 'Teilgenommen',
                    'data' => $labels->map(fn($label) => $attendedData[$label] ?? 0),
                    'borderColor' => 'rgb(75, 192, 192)',
                    'fill' => false,
                ],
                [
                    'label' => 'Nicht teilgenommen',
                    'data' => $labels->map(fn($label) => $notAttendedData[$label] ?? 0),
                    'borderColor' => 'rgb(255, 99, 132)',
                    'fill' => false,
                ],
            ],
            'labels' => $labels->toArray(),
        ];
    }
}
