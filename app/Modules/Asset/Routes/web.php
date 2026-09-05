<?php

use App\Modules\Asset\Http\Controllers\AssetDonationController;
use App\Modules\Asset\Http\Controllers\AssetGoodsReceiptController;
use App\Modules\Asset\Http\Controllers\AssetPurchaseOrderController;
use App\Modules\Asset\Http\Controllers\AssetRequisitionController;
use App\Modules\Asset\Http\Controllers\AssetTransferController;
use App\Modules\Asset\Http\Controllers\CssdController;
use App\Modules\Asset\Http\Controllers\EnvironmentalHealthController;
use App\Modules\Asset\Http\Controllers\MaintenanceController;
use App\Modules\Asset\Http\Controllers\MasterDataController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])
    ->prefix('aset')
    ->name('asset.')
    ->group(function () {

        Route::middleware('can:inventaris_inventaris')->group(function () {
            Route::get('/', [MasterDataController::class, 'index'])->name('index');
            Route::post('/aset', [MasterDataController::class, 'storeAsset'])->name('aset.simpan');
            Route::post('/aset/{aset}', [MasterDataController::class, 'updateAsset'])->name('aset.perbarui');
            Route::post('/kategori', [MasterDataController::class, 'storeCategory'])->name('kategori.simpan');
            Route::post('/lokasi', [MasterDataController::class, 'storeLocation'])->name('lokasi.simpan');
            // Domain G item A — inventaris_jenis, inventaris_produsen. Lihat
            // catatan migrasi 2026_10_08_000001.
            Route::post('/jenis', [MasterDataController::class, 'storeType'])->name('jenis.simpan');
            Route::post('/produsen', [MasterDataController::class, 'storeManufacturer'])->name('produsen.simpan');
        });

        Route::middleware('can:perbaikan_inventaris')->prefix('pemeliharaan')->name('pemeliharaan.')->group(function () {
            Route::get('/', [MaintenanceController::class, 'index'])->name('index');
            Route::post('/', [MaintenanceController::class, 'store'])->name('simpan');
            Route::post('/{permintaan}/mulai', [MaintenanceController::class, 'start'])->name('mulai');
            Route::post('/{permintaan}/selesai', [MaintenanceController::class, 'complete'])->name('selesai');
            Route::post('/{permintaan}/tolak', [MaintenanceController::class, 'reject'])->name('tolak');
        });

        Route::middleware('can:sirkulasi_cssd')->prefix('cssd')->name('cssd.')->group(function () {
            Route::get('/', [CssdController::class, 'index'])->name('index');
            Route::post('/set', [CssdController::class, 'storeItem'])->name('set.simpan');
            Route::post('/terima', [CssdController::class, 'receive'])->name('terima');
            Route::post('/{sirkulasi}/proses', [CssdController::class, 'startProcessing'])->name('proses');
            Route::post('/{sirkulasi}/steril', [CssdController::class, 'markSterile'])->name('steril');
            Route::post('/{sirkulasi}/distribusi', [CssdController::class, 'distribute'])->name('distribusi');
        });

        Route::middleware('can:limbah_b3_medis')->prefix('kesling')->name('kesling.')->group(function () {
            Route::get('/', [EnvironmentalHealthController::class, 'index'])->name('index');
            Route::post('/pengukuran', [EnvironmentalHealthController::class, 'storeMeasurement'])->name('pengukuran.simpan');
            Route::post('/pest-control', [EnvironmentalHealthController::class, 'storePestControl'])->name('pest-control.simpan');
        });

        // Domain G item B — rantai pengadaan aset. Lihat catatan migrasi
        // 2026_10_09_000001_create_asset_procurement_tables. Satu gerbang
        // pengajuan_asetinventaris juga menaungi rekap_pengajuan_aset_
        // departemen (tab rekap di layar yang sama, cuma 1 kode).
        Route::middleware('can:pengajuan_asetinventaris')->prefix('pengajuan')->name('pengajuan.')->group(function () {
            Route::get('/', [AssetRequisitionController::class, 'index'])->name('index');
            Route::post('/', [AssetRequisitionController::class, 'store'])->name('simpan');
            Route::post('/{pengajuan}/setuju', [AssetRequisitionController::class, 'approve'])->name('setuju');
            Route::post('/{pengajuan}/tolak', [AssetRequisitionController::class, 'reject'])->name('tolak');
        });

        Route::middleware('can:pengadaan_aset_inventaris')->prefix('po')->name('po.')->group(function () {
            Route::get('/', [AssetPurchaseOrderController::class, 'index'])->name('index');
            Route::get('/{po}', [AssetPurchaseOrderController::class, 'show'])->name('show');
            Route::post('/', [AssetPurchaseOrderController::class, 'store'])->name('simpan');
            Route::post('/{po}/kirim', [AssetPurchaseOrderController::class, 'submit'])->name('kirim');
            Route::post('/{po}/batal', [AssetPurchaseOrderController::class, 'cancel'])->name('batal');
        });

        // suplier_inventaris — kode Khanza tersendiri (beda dari domain
        // E/F yang tidak punya padanan), gerbang literal terpisah.
        Route::middleware('can:suplier_inventaris')->post('/suplier', [AssetPurchaseOrderController::class, 'storeSupplier'])->name('suplier.simpan');

        // penerimaan_aset_inventaris — satu gerbang sama dengan pengadaan
        // (peran yang sama menerima barang yang dipesannya, RS kecil ini
        // tidak memisahkan petugas gudang dari pemesan).
        Route::middleware('can:penerimaan_aset_inventaris')->post('/penerimaan/po/{po}', [AssetGoodsReceiptController::class, 'store'])->name('penerimaan.simpan');

        Route::middleware('can:hibah_aset_inventaris')->prefix('hibah')->name('hibah.')->group(function () {
            Route::get('/', [AssetDonationController::class, 'index'])->name('index');
            Route::post('/donor', [AssetDonationController::class, 'storeDonor'])->name('donor.simpan');
            Route::post('/', [AssetDonationController::class, 'store'])->name('simpan');
        });

        // Domain G item C — inventaris_sirkulasi, riwayat perpindahan
        // satu aset antar lokasi. Lihat catatan migrasi 2026_10_10_000001.
        Route::middleware('can:inventaris_sirkulasi')->prefix('sirkulasi')->name('sirkulasi.')->group(function () {
            Route::get('/', [AssetTransferController::class, 'index'])->name('index');
            Route::post('/', [AssetTransferController::class, 'store'])->name('simpan');
        });

    });
