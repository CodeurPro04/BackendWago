<?php

use App\Http\Controllers\PaymentReturnController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/pay/return', [PaymentReturnController::class, 'handle']);
