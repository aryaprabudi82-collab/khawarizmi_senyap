<?php

use App\Modules\Reporting\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'can:rekap_kunjungan'])
    ->prefix('laporan')
    ->name('reporting.')
    ->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::post('/sinkron', [DashboardController::class, 'sync'])->name('sinkron');
    });
