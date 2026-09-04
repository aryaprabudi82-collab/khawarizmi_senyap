<?php

use App\Modules\Pharmacy\Http\Controllers\MasterDataController;
use App\Modules\Pharmacy\Http\Controllers\PrescriptionController;
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
