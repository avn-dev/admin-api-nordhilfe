<?php

namespace App\Filament\Widgets;

use App\Models\Location;
use Filament\Widgets\BarChartWidget;

class ParticipantsPerLocationChart extends BarChartWidget
{
    protected static ?string $heading = 'Teilnehmer pro Ort';

    protected function getData(): array
    {
        $locations = Location::with('trainingSessions.participants')->get();

        $labels = [];
        $attendedCounts = [];
        $notAttendedCounts = [];

        foreach ($locations as $location) {
            $participants = $location->trainingSessions->flatMap->participants;

            $labels[] = $location->name;
            $attendedCounts[] = $participants->where('attended', true)->count();
            $notAttendedCounts[] = $participants->where('attended', false)->count();
        }

        return [
            'datasets' => [
                [
                    'label' => 'Teilgenommen',
                    'data' => $attendedCounts,
                    'backgroundColor' => 'rgb(75, 192, 192)',
                ],
                [
                    'label' => 'Nicht teilgenommen',
                    'data' => $notAttendedCounts,
                    'backgroundColor' => 'rgb(255, 99, 132)',
                ],
            ],
            'labels' => $labels,
        ];
    }
}
