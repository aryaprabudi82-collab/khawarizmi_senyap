<?php

use App\Modules\Encounter\Http\Controllers\RegistrationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function () {
    Route::prefix('registrasi')->name('registrasi.')->group(function () {
        Route::get('/', [RegistrationController::class, 'index'])->name('index')->can('registrasi');
        Route::get('/baru', [RegistrationController::class, 'create'])->name('create')->can('registrasi');
        Route::post('/', [RegistrationController::class, 'store'])->name('store')->can('registrasi');
        Route::post('/{registrasi}/batal', [RegistrationController::class, 'cancel'])->name('batal')->can('registrasi');
    });
});
