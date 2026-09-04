<?php

use App\Modules\Inventory\Http\Controllers\GoodsReceiptController;
use App\Modules\Inventory\Http\Controllers\MasterDataController;
use App\Modules\Inventory\Http\Controllers\PurchaseOrderController;
use App\Modules\Inventory\Http\Controllers\RequisitionController;
use App\Modules\Inventory\Http\Controllers\SupplierReturnController;
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

        // permintaan_non_medis (IPSRSPermintaan) TIDAK jadi layar sendiri —
        // sudah terpenuhi listing di sini, sama seperti resep_dokter di
        // domain D. ipsrs_stok_keluar juga sudah terpenuhi lewat
        // fulfill()/StockLedger::issue() di bawah.
        Route::middleware('can:pengajuan_barang_nonmedis')->prefix('permintaan')->name('permintaan.')->group(function () {
            Route::get('/', [RequisitionController::class, 'index'])->name('index');
            Route::post('/', [RequisitionController::class, 'store'])->name('simpan');
            Route::post('/{permintaan}/setuju', [RequisitionController::class, 'approve'])->name('setuju');
            Route::post('/{permintaan}/tolak', [RequisitionController::class, 'reject'])->name('tolak');
            Route::post('/{permintaan}/keluarkan', [RequisitionController::class, 'fulfill'])->name('keluarkan');
        });

        // Domain E item B — rantai pengadaan ke suplier. Lihat catatan
        // migrasi 2026_10_01_000001_create_inventory_procurement_tables.
        Route::middleware('can:ipsrs_pengadaan_barang')->prefix('po')->name('po.')->group(function () {
            Route::get('/', [PurchaseOrderController::class, 'index'])->name('index');
            Route::get('/{po}', [PurchaseOrderController::class, 'show'])->name('show');
            Route::post('/', [PurchaseOrderController::class, 'store'])->name('simpan');
            Route::post('/{po}/kirim', [PurchaseOrderController::class, 'submit'])->name('kirim');
            Route::post('/{po}/batal', [PurchaseOrderController::class, 'cancel'])->name('batal');
        });

        // surat_pemesanan_non_medis — cetak dari data PO yang sama, gerbang terpisah.
        Route::middleware('can:surat_pemesanan_non_medis')->get('/po/{po}/cetak', [PurchaseOrderController::class, 'print'])->name('po.cetak');

        Route::middleware('can:penerimaan_non_medis')->prefix('penerimaan')->name('penerimaan.')->group(function () {
            Route::get('/', [GoodsReceiptController::class, 'index'])->name('index');
            Route::post('/po/{po}', [GoodsReceiptController::class, 'store'])->name('simpan');
        });

        Route::middleware('can:verifikasi_penerimaan_logistik')->post('/penerimaan/{penerimaan}/verifikasi', [GoodsReceiptController::class, 'verify'])->name('penerimaan.verifikasi');

        Route::middleware('can:ipsrs_returbeli')->prefix('retur')->name('retur.')->group(function () {
            Route::get('/', [SupplierReturnController::class, 'index'])->name('index');
            Route::post('/', [SupplierReturnController::class, 'store'])->name('simpan');
            Route::post('/{retur}/selesai', [SupplierReturnController::class, 'complete'])->name('selesai');
        });

    });
