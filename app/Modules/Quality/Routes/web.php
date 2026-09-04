<?php

use App\Modules\Quality\Http\Controllers\IcraController;
use App\Modules\Quality\Http\Controllers\IncidentController;
use App\Modules\Quality\Http\Controllers\K3IncidentController;
use App\Modules\Quality\Http\Controllers\K3RecapController;
use App\Modules\Quality\Http\Controllers\PpiAuditController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])
    ->prefix('mutu')
    ->name('quality.')
    ->group(function () {

        Route::middleware('can:insiden_keselamatan_pasien')->prefix('insiden')->name('insiden.')->group(function () {
            Route::get('/', [IncidentController::class, 'index'])->name('index');
            Route::post('/', [IncidentController::class, 'store'])->name('simpan');
            Route::post('/{insiden}/tinjau', [IncidentController::class, 'review'])->name('tinjau');
            Route::post('/{insiden}/tutup', [IncidentController::class, 'close'])->name('tutup');
        });

        Route::middleware('can:pcra_icra_pengkajian_risiko_prakonstruksi')->prefix('icra')->name('icra.')->group(function () {
            Route::get('/', [IcraController::class, 'index'])->name('index');
            Route::post('/', [IcraController::class, 'store'])->name('simpan');
            Route::post('/{kajian}/selesai', [IcraController::class, 'complete'])->name('selesai');
            Route::post('/{kajian}/batal', [IcraController::class, 'cancel'])->name('batal');
        });

        Route::middleware('can:audit_kepatuhan_apd')->prefix('ppi')->name('ppi.')->group(function () {
            Route::get('/', [PpiAuditController::class, 'index'])->name('index');
            Route::post('/', [PpiAuditController::class, 'store'])->name('simpan');
        });

        Route::middleware('can:peristiwa_k3rs')->prefix('k3')->name('k3.')->group(function () {
            Route::get('/', [K3IncidentController::class, 'index'])->name('index');
            Route::post('/', [K3IncidentController::class, 'store'])->name('simpan');
            Route::post('/{insiden}/tinjau', [K3IncidentController::class, 'review'])->name('tinjau');
            Route::post('/{insiden}/tutup', [K3IncidentController::class, 'close'])->name('tutup');
        });

        Route::middleware('can:jenis_cidera_k3rstahun')->get('/k3/rekap', [K3RecapController::class, 'index'])->name('k3.rekap');

    });
