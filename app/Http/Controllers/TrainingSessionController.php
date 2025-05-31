<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\TrainingSession;
use Illuminate\Support\Carbon;

class TrainingSessionController extends Controller
{
    public function index()
    {
        $today = Carbon::today();

        $sessions = TrainingSession::with(['course', 'location'])
            ->withCount('participants')
            ->whereDate('session_date', '>=', $today)
            ->get()
            ->filter(function ($session) {
                if ($session->max_participants !== null) {
                    return $session->participants_count < $session->max_participants;
                }
                return true;
            })
            ->values();

        return response()->json($sessions);
    }
}
