<?php

use App\Modules\Kitchen\Http\Controllers\MasterDataController;
use App\Modules\Kitchen\Http\Controllers\RequisitionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])
    ->prefix('dapur')
    ->name('kitchen.')
    ->group(function () {

        Route::middleware('can:dapur_barang')->group(function () {
            Route::get('/', [MasterDataController::class, 'index'])->name('index');
            Route::post('/barang', [MasterDataController::class, 'storeItem'])->name('barang.simpan');
            Route::post('/barang/{barang}', [MasterDataController::class, 'updateItem'])->name('barang.perbarui');
            Route::post('/kategori', [MasterDataController::class, 'storeCategory'])->name('kategori.simpan');
            Route::post('/suplier', [MasterDataController::class, 'storeSupplier'])->name('suplier.simpan');
            Route::post('/barang/{barang}/masuk', [MasterDataController::class, 'receive'])->name('barang.masuk');
            Route::post('/barang/{barang}/opname', [MasterDataController::class, 'opname'])->name('barang.opname');
        });

        // permintaan_dapur (menu terpisah di Khanza) TIDAK jadi layar
        // sendiri — sudah terpenuhi listing di sini, pola identik dengan
        // permintaan_non_medis di domain E item A. dapur_stok_keluar
        // (item B) juga akan terpenuhi lewat fulfill()/StockLedger::issue()
        // di bawah, sama seperti ipsrs_stok_keluar.
        Route::middleware('can:pengajuan_barang_dapur')->prefix('permintaan')->name('permintaan.')->group(function () {
            Route::get('/', [RequisitionController::class, 'index'])->name('index');
            Route::post('/', [RequisitionController::class, 'store'])->name('simpan');
            Route::post('/{permintaan}/setuju', [RequisitionController::class, 'approve'])->name('setuju');
            Route::post('/{permintaan}/tolak', [RequisitionController::class, 'reject'])->name('tolak');
            Route::post('/{permintaan}/keluarkan', [RequisitionController::class, 'fulfill'])->name('keluarkan');
        });

    });
