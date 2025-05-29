<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\TrainingSession;

class TrainingSessionController extends Controller
{
    public function index()
    {
        $sessions = TrainingSession::with([
            'course',
            'location'
        ])
        ->withCount('participants')
        ->get();

        return response()->json($sessions);
    }
}
