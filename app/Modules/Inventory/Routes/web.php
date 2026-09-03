<?php

use App\Modules\Inventory\Http\Controllers\MasterDataController;
use App\Modules\Inventory\Http\Controllers\RequisitionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])
    ->prefix('logistik')
    ->name('inventory.')
    ->group(function () {

        Route::middleware('can:ipsrs_barang')->group(function () {
            Route::get('/', [MasterDataController::class, 'index'])->name('index');
            Route::post('/barang', [MasterDataController::class, 'storeItem'])->name('barang.simpan');
            Route::post('/barang/{barang}', [MasterDataController::class, 'updateItem'])->name('barang.perbarui');
            Route::post('/kategori', [MasterDataController::class, 'storeCategory'])->name('kategori.simpan');
            Route::post('/suplier', [MasterDataController::class, 'storeSupplier'])->name('suplier.simpan');
            Route::post('/barang/{barang}/masuk', [MasterDataController::class, 'receive'])->name('barang.masuk');
            Route::post('/barang/{barang}/opname', [MasterDataController::class, 'opname'])->name('barang.opname');
        });

        Route::middleware('can:pengajuan_barang_nonmedis')->prefix('permintaan')->name('permintaan.')->group(function () {
            Route::get('/', [RequisitionController::class, 'index'])->name('index');
            Route::post('/', [RequisitionController::class, 'store'])->name('simpan');
            Route::post('/{permintaan}/setuju', [RequisitionController::class, 'approve'])->name('setuju');
            Route::post('/{permintaan}/tolak', [RequisitionController::class, 'reject'])->name('tolak');
            Route::post('/{permintaan}/keluarkan', [RequisitionController::class, 'fulfill'])->name('keluarkan');
        });

    });
