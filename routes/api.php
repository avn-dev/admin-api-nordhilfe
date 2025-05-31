<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TrainingSessionController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\BookingController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');



Route::get('/trainingSessions', [TrainingSessionController::class, 'index']);
Route::get('/courses', [CourseController::class, 'index']);
Route::get('/locations', [LocationController::class, 'index']);
Route::post('/bookings', [BookingController::class, 'store']);
Route::post('/bookings/paypal-complete', [BookingController::class, 'paypalComplete']);