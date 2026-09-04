<?php

use App\Modules\Pharmacy\Http\Controllers\DrugRequisitionController;
use App\Modules\Pharmacy\Http\Controllers\GoodsReceiptController;
use App\Modules\Pharmacy\Http\Controllers\MasterDataController;
use App\Modules\Pharmacy\Http\Controllers\PrescriptionController;
use App\Modules\Pharmacy\Http\Controllers\PurchaseOrderController;
use App\Modules\Pharmacy\Http\Controllers\SupplierReturnController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function () {
    // Domain D item 1 (master data) — obat digerbangi 'obat', menaungi 8 kode
    // master data farmasi lain sebagai satu layar gabungan (jenis_barang sudah
    // terpenuhi drugs.category, tidak ada di sini — lihat catatan migrasi).
    Route::middleware('can:obat')->prefix('farmasi/master')->name('pharmacy.master.')->group(function () {
        Route::get('/', [MasterDataController::class, 'index'])->name('index');
        Route::post('/obat', [MasterDataController::class, 'storeDrug'])->name('obat.simpan');
        Route::post('/kategori', [MasterDataController::class, 'storeDrugCategory'])->name('kategori.simpan');
        Route::post('/golongan', [MasterDataController::class, 'storeDrugClass'])->name('golongan.simpan');
        Route::post('/satuan', [MasterDataController::class, 'storeUnit'])->name('satuan.simpan');
        Route::post('/suplier', [MasterDataController::class, 'storeSupplier'])->name('suplier.simpan');
        Route::post('/industri', [MasterDataController::class, 'storeManufacturer'])->name('industri.simpan');
        Route::post('/metode-racik', [MasterDataController::class, 'storeCompoundingMethod'])->name('metode-racik.simpan');
        Route::post('/obat/{obat}/konversi', [MasterDataController::class, 'storeDrugUnit'])->name('konversi.simpan');
    });

    // Domain D item 2 — rantai pengadaan (pengajuan -> PO -> terima+bayar ->
    // verifikasi -> retur). Desain dikonfirmasi user, lihat catatan migrasi
    // 2026_09_27_000001_create_pharmacy_procurement_tables.
    Route::middleware('can:pengajuan_barang_medis')->prefix('farmasi/pengajuan')->name('pharmacy.pengajuan.')->group(function () {
        Route::get('/', [DrugRequisitionController::class, 'index'])->name('index');
        Route::post('/', [DrugRequisitionController::class, 'store'])->name('simpan');
        Route::post('/{pengajuan}/setuju', [DrugRequisitionController::class, 'approve'])->name('setuju');
        Route::post('/{pengajuan}/tolak', [DrugRequisitionController::class, 'reject'])->name('tolak');
    });

    Route::middleware('can:pengadaan_obat')->prefix('farmasi/po')->name('pharmacy.po.')->group(function () {
        Route::get('/', [PurchaseOrderController::class, 'index'])->name('index');
        Route::get('/{po}', [PurchaseOrderController::class, 'show'])->name('show');
        Route::post('/', [PurchaseOrderController::class, 'store'])->name('simpan');
        Route::post('/{po}/kirim', [PurchaseOrderController::class, 'submit'])->name('kirim');
        Route::post('/{po}/batal', [PurchaseOrderController::class, 'cancel'])->name('batal');
    });

    // pemesanan_obat (Surat Pemesanan) — cetak dari data PO yang sama, gerbang terpisah.
    Route::middleware('can:pemesanan_obat')->get('/farmasi/po/{po}/cetak', [PurchaseOrderController::class, 'print'])->name('pharmacy.po.cetak');

    Route::middleware('can:bayar_pemesanan_obat')->prefix('farmasi/penerimaan')->name('pharmacy.penerimaan.')->group(function () {
        Route::get('/', [GoodsReceiptController::class, 'index'])->name('index');
        Route::post('/po/{po}', [GoodsReceiptController::class, 'store'])->name('simpan');
    });

    Route::middleware('can:verifikasi_penerimaan_farmasi')->post('/farmasi/penerimaan/{penerimaan}/verifikasi', [GoodsReceiptController::class, 'verify'])->name('pharmacy.penerimaan.verifikasi');

    Route::middleware('can:retur_ke_suplier')->prefix('farmasi/retur')->name('pharmacy.retur.')->group(function () {
        Route::get('/', [SupplierReturnController::class, 'index'])->name('index');
        Route::post('/', [SupplierReturnController::class, 'store'])->name('simpan');
        Route::post('/{retur}/selesai', [SupplierReturnController::class, 'complete'])->name('selesai');
    });

    Route::prefix('resep')->name('resep.')->group(function () {
        // Antrean farmasi dan rincian resep: apoteker maupun dokter penulis.
        Route::get('/', [PrescriptionController::class, 'index'])->name('index');
        Route::get('/cari-obat', [PrescriptionController::class, 'searchDrugs'])->name('cari-obat');
        Route::get('/{resep}', [PrescriptionController::class, 'show'])->name('show');

        // Penyusunan resep oleh dokter.
        Route::middleware('can:resep_obat')->group(function () {
            Route::post('/kunjungan/{registrasi}', [PrescriptionController::class, 'createForRegistration'])->name('buat');
            Route::post('/{resep}/item', [PrescriptionController::class, 'storeItem'])->name('item.simpan');
            Route::delete('/item/{item}', [PrescriptionController::class, 'destroyItem'])->name('item.hapus');
            Route::post('/{resep}/kirim', [PrescriptionController::class, 'submit'])->name('kirim');
        });

        // Telaah dan penyerahan oleh apoteker.
        Route::post('/{resep}/telaah', [PrescriptionController::class, 'review'])
            ->name('telaah')->middleware('can:telaah_resep');
        Route::post('/{resep}/serah', [PrescriptionController::class, 'dispense'])
            ->name('serah')->middleware('can:beri_obat');

        Route::post('/{resep}/batal', [PrescriptionController::class, 'cancel'])->name('batal');
    });
});
