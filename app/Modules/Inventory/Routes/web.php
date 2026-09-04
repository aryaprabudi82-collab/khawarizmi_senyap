<?php

use App\Modules\Inventory\Http\Controllers\DonationController;
use App\Modules\Inventory\Http\Controllers\GoodsReceiptController;
use App\Modules\Inventory\Http\Controllers\InventoryRecapController;
use App\Modules\Inventory\Http\Controllers\MasterDataController;
use App\Modules\Inventory\Http\Controllers\PurchaseOrderController;
use App\Modules\Inventory\Http\Controllers\RequisitionController;
use App\Modules\Inventory\Http\Controllers\StockOpnameController;
use App\Modules\Inventory\Http\Controllers\StockReportController;
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
        // domain D. ipsrs_stok_keluar dan pengambilan_penunjang_utd juga
        // sudah terpenuhi lewat fulfill()/StockLedger::issue() di bawah —
        // UTD cuma unit pemohon biasa, sama pola dengan UTD di domain D
        // item 4. Lihat catatan migrasi 2026_10_02_000001.
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

        // Domain E item C — stok opname (sesi multi-barang, beda dari
        // opname() ad-hoc di atas) & riwayat/sirkulasi barang. Lihat
        // catatan migrasi 2026_10_02_000001.
        Route::middleware('can:stok_opname_logistik')->prefix('opname')->name('opname.')->group(function () {
            Route::get('/', [StockOpnameController::class, 'index'])->name('index');
            Route::get('/{opname}', [StockOpnameController::class, 'show'])->name('show');
            Route::post('/', [StockOpnameController::class, 'store'])->name('simpan');
            Route::post('/{opname}/hitung', [StockOpnameController::class, 'recordCount'])->name('hitung');
            Route::post('/{opname}/selesai', [StockOpnameController::class, 'complete'])->name('selesai');
        });

        // ipsrs_riwayat_barang menaungi sirkulasi_non_medis dan
        // sirkulasi_non_medis2 — satu layar gabungan, lihat catatan
        // migrasi 2026_10_02_000001.
        Route::middleware('can:ipsrs_riwayat_barang')->get('/laporan', [StockReportController::class, 'index'])->name('laporan.index');

        // Domain E item D (terakhir) — hibah barang & rekap gabungan.
        // Lihat catatan migrasi 2026_10_03_000001.
        Route::middleware('can:hibah_non_medis')->prefix('hibah')->name('hibah.')->group(function () {
            Route::get('/', [DonationController::class, 'index'])->name('index');
            Route::post('/donor', [DonationController::class, 'storeDonor'])->name('donor.simpan');
            Route::post('/', [DonationController::class, 'store'])->name('simpan');
        });

        // ipsrs_rekap_pengadaan menaungi 13 kode ringkasan/rekap lain —
        // satu layar gabungan, lihat catatan migrasi 2026_10_03_000001.
        Route::middleware('can:ipsrs_rekap_pengadaan')->get('/rekap', [InventoryRecapController::class, 'index'])->name('rekap.index');

    });
