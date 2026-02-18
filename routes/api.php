<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\DriverJobController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ReferenceController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/mobile-login', [AuthController::class, 'mobileLogin']);
Route::get('/health', fn () => response()->json(['ok' => true]));

Route::get('/reference/services', [ReferenceController::class, 'services']);
Route::get('/users/{user}/profile', [ProfileController::class, 'show']);
Route::patch('/users/{user}/profile', [ProfileController::class, 'update']);
Route::post('/drivers/{driver}/approve', [ProfileController::class, 'approveDriver']);
Route::post('/drivers/{driver}/documents', [ProfileController::class, 'uploadDocument']);
Route::post('/drivers/{driver}/documents/submit', [ProfileController::class, 'submitDocuments']);
Route::post('/users/{user}/avatar', [ProfileController::class, 'uploadAvatar']);

Route::post('/bookings', [BookingController::class, 'store']);
Route::get('/bookings/{booking}', [BookingController::class, 'show']);
Route::get('/customers/{customer}/bookings', [BookingController::class, 'customerBookings']);
Route::patch('/bookings/{booking}/cancel', [BookingController::class, 'cancel']);
Route::patch('/bookings/{booking}/rate', [BookingController::class, 'rate']);
Route::post('/bookings/{booking}/media', [BookingController::class, 'uploadMedia']);

Route::get('/drivers/{driver}/jobs', [DriverJobController::class, 'jobs']);
Route::patch('/drivers/{driver}/availability', [DriverJobController::class, 'updateAvailability']);
Route::post('/jobs/{booking}/accept', [DriverJobController::class, 'accept']);
Route::post('/jobs/{booking}/decline', [DriverJobController::class, 'decline']);
Route::post('/jobs/{booking}/transition', [DriverJobController::class, 'transition']);
