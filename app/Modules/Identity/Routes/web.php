<?php

use App\Modules\Identity\Http\Controllers\PatientController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function () {
    Route::prefix('pasien')->name('pasien.')->group(function () {
        Route::get('/', [PatientController::class, 'index'])->name('index')->can('pasien');
        Route::get('/baru', [PatientController::class, 'create'])->name('create')->can('pasien');
        Route::post('/', [PatientController::class, 'store'])->name('store')->can('pasien');
    });
});
