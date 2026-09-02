<?php

use App\Modules\Finance\Http\Controllers\ReceivableController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'can:bayar_piutang'])->group(function () {
    Route::prefix('piutang')->name('piutang.')->group(function () {
        Route::get('/', [ReceivableController::class, 'index'])->name('index');
        Route::post('/{piutang}/tagih', [ReceivableController::class, 'collect'])->name('tagih');
    });
});
