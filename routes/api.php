<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\DriverJobController;
use App\Http\Controllers\Api\DriverNotificationController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ReferenceController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/mobile-login', [AuthController::class, 'mobileLogin']);
Route::post('/auth/otp/send', [AuthController::class, 'sendOtp']);
Route::post('/auth/otp/verify', [AuthController::class, 'verifyOtp']);
Route::post('/auth/email/register', [AuthController::class, 'registerWithEmail']);
Route::post('/auth/email/login', [AuthController::class, 'loginWithEmail']);
Route::get('/auth/oauth/start', [AuthController::class, 'oauthStart']);
Route::post('/auth/oauth/mobile-complete', [AuthController::class, 'oauthMobileComplete']);
Route::get('/health', fn () => response()->json(['ok' => true]));

Route::get('/reference/services', [ReferenceController::class, 'services']);
Route::get('/users/{user}/profile', [ProfileController::class, 'show']);
Route::patch('/users/{user}/profile', [ProfileController::class, 'update']);
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
Route::get('/drivers/{driver}/notifications', [DriverNotificationController::class, 'index']);
Route::patch('/drivers/{driver}/notifications/read-all', [DriverNotificationController::class, 'markAllRead']);
Route::patch('/drivers/{driver}/notifications/{notification}/read', [DriverNotificationController::class, 'markRead']);
Route::delete('/drivers/{driver}/notifications', [DriverNotificationController::class, 'clear']);
Route::get('/drivers/{driver}/wallet/transactions', [DriverNotificationController::class, 'walletTransactions']);
Route::post('/drivers/{driver}/wallet/transactions', [DriverNotificationController::class, 'storeWalletTransaction']);
Route::post('/drivers/{driver}/device', [DriverNotificationController::class, 'registerDevice']);
Route::post('/jobs/{booking}/accept', [DriverJobController::class, 'accept']);
Route::post('/jobs/{booking}/decline', [DriverJobController::class, 'decline']);
Route::post('/jobs/{booking}/transition', [DriverJobController::class, 'transition']);

Route::get('/admin/dashboard', [AdminController::class, 'dashboard']);
Route::get('/admin/drivers', [AdminController::class, 'drivers']);
Route::get('/admin/drivers/{driver}', [AdminController::class, 'driverDetails']);
Route::patch('/admin/drivers/{driver}/ban', [AdminController::class, 'setDriverBan']);
Route::patch('/admin/drivers/{driver}/review', [AdminController::class, 'updateDriverReview']);
Route::post('/admin/announcements/drivers', [AdminController::class, 'sendDriverAnnouncement']);
Route::get('/admin/announcements', [AdminController::class, 'announcements']);
Route::get('/admin/customers', [AdminController::class, 'customers']);
Route::get('/admin/bookings', [AdminController::class, 'bookings']);
