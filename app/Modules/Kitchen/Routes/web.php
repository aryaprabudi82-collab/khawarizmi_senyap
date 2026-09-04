<?php

use App\Modules\Kitchen\Http\Controllers\GoodsReceiptController;
use App\Modules\Kitchen\Http\Controllers\MasterDataController;
use App\Modules\Kitchen\Http\Controllers\PurchaseOrderController;
use App\Modules\Kitchen\Http\Controllers\RequisitionController;
use App\Modules\Kitchen\Http\Controllers\SupplierReturnController;
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

        // Domain F item B — rantai pengadaan ke suplier dapur. Lihat
        // catatan migrasi 2026_10_05_000001_create_kitchen_procurement_tables.
        Route::middleware('can:dapur_pembelian')->prefix('po')->name('po.')->group(function () {
            Route::get('/', [PurchaseOrderController::class, 'index'])->name('index');
            Route::get('/{po}', [PurchaseOrderController::class, 'show'])->name('show');
            Route::post('/', [PurchaseOrderController::class, 'store'])->name('simpan');
            Route::post('/{po}/kirim', [PurchaseOrderController::class, 'submit'])->name('kirim');
            Route::post('/{po}/batal', [PurchaseOrderController::class, 'cancel'])->name('batal');
        });

        // surat_pemesanan_dapur — cetak dari data PO yang sama, gerbang terpisah.
        Route::middleware('can:surat_pemesanan_dapur')->get('/po/{po}/cetak', [PurchaseOrderController::class, 'print'])->name('po.cetak');

        Route::middleware('can:dapur_pemesanan')->prefix('penerimaan')->name('penerimaan.')->group(function () {
            Route::get('/', [GoodsReceiptController::class, 'index'])->name('index');
            Route::post('/po/{po}', [GoodsReceiptController::class, 'store'])->name('simpan');
        });

        Route::middleware('can:verifikasi_penerimaan_dapur')->post('/penerimaan/{penerimaan}/verifikasi', [GoodsReceiptController::class, 'verify'])->name('penerimaan.verifikasi');

        Route::middleware('can:dapur_returbeli')->prefix('retur')->name('retur.')->group(function () {
            Route::get('/', [SupplierReturnController::class, 'index'])->name('index');
            Route::post('/', [SupplierReturnController::class, 'store'])->name('simpan');
            Route::post('/{retur}/selesai', [SupplierReturnController::class, 'complete'])->name('selesai');
        });

    });
