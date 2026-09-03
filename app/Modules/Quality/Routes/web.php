<?php

use App\Modules\Quality\Http\Controllers\IcraController;
use App\Modules\Quality\Http\Controllers\IncidentController;
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

    });
