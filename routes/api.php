<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TrainingSessionController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\PayPalController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');



Route::get('/trainingSessions', [TrainingSessionController::class, 'index']);
Route::get('/courses', [CourseController::class, 'index']);
Route::get('/locations', [LocationController::class, 'index']);
Route::post('/bookings', [BookingController::class, 'store']);
Route::post('/bookings/paypal-complete', [BookingController::class, 'paypalComplete']);

Route::get('/paypal/config', [PayPalController::class, 'config']);
Route::post('/paypal/order', [PayPalController::class, 'createOrder']);
Route::post('/paypal/capture', [PayPalController::class, 'capture']);
Route::post('/paypal/webhook', [PayPalController::class, 'webhook']);