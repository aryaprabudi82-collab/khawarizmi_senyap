<?php

use App\Modules\Platform\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('/masuk', [AuthController::class, 'form'])->name('masuk');
        Route::post('/masuk', [AuthController::class, 'login'])->name('masuk.kirim');
    });

    Route::middleware('auth')->group(function () {
        Route::post('/keluar', [AuthController::class, 'logout'])->name('keluar');
    });
});
