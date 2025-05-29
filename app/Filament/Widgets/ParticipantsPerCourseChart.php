<?php

namespace App\Filament\Widgets;

use App\Models\Course;
use Filament\Widgets\BarChartWidget;

class ParticipantsPerCourseChart extends BarChartWidget
{
    protected static ?string $heading = 'Teilnehmer pro Kurs';

    protected function getData(): array
    {
        $courses = Course::with('trainingSessions.participants')->get();

        $labels = [];
        $attendedCounts = [];
        $notAttendedCounts = [];

        foreach ($courses as $course) {
            $participants = $course->trainingSessions->flatMap->participants;

            $labels[] = $course->name;
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
