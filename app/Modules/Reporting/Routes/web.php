<?php

use App\Modules\Reporting\Http\Controllers\CensusReportController;
use App\Modules\Reporting\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'can:rekap_kunjungan'])
    ->prefix('laporan')
    ->name('reporting.')
    ->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::post('/sinkron', [DashboardController::class, 'sync'])->name('sinkron');
    });

/*
| Domain J item A: sensus & kunjungan. Satu layar berpenyaring menaungi
| 21 kode laporan Khanza — semuanya potongan berbeda dari data yang sama
| (registrasi, admisi, permintaan penunjang). Digerbangi sensus_harian_ralan
| sebagai kode yang paling mewakili, pola umbrella seperti domain I item D.
*/
Route::middleware(['web', 'auth', 'can:sensus_harian_ralan'])
    ->prefix('laporan')
    ->name('reporting.')
    ->group(function () {
        Route::get('/sensus', [CensusReportController::class, 'index'])->name('sensus');
    });
