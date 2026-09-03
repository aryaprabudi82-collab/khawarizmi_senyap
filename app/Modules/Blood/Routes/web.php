<?php

use App\Modules\Blood\Http\Controllers\DonorController;
use App\Modules\Blood\Http\Controllers\StockController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])
    ->prefix('utd')
    ->name('blood.')
    ->group(function () {

        Route::middleware('can:utd_pendonor')->prefix('pendonor')->name('pendonor.')->group(function () {
            Route::get('/', [DonorController::class, 'index'])->name('index');
            Route::post('/', [DonorController::class, 'store'])->name('simpan');
            Route::post('/{pendonor}', [DonorController::class, 'update'])->name('perbarui');
        });

        Route::middleware('can:utd_stok_darah')->prefix('stok')->name('stok.')->group(function () {
            Route::get('/', [StockController::class, 'index'])->name('index');
            Route::post('/', [StockController::class, 'collect'])->name('simpan');
            Route::post('/{unit}/rilis', [StockController::class, 'release'])->name('rilis');
            Route::post('/{unit}/tahan', [StockController::class, 'hold'])->name('tahan');
            Route::post('/{unit}/tolak', [StockController::class, 'reject'])->name('tolak');
        });

        Route::middleware('can:utd_penyerahan_darah')->post('/stok/{unit}/serahkan', [StockController::class, 'issue'])->name('stok.serahkan');

    });
