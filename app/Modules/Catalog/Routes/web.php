<?php

use App\Modules\Catalog\Http\Controllers\MasterDataController;
use Illuminate\Support\Facades\Route;

/*
| Khanza tidak punya permission khusus untuk CRUD data master seperti ini -
| menunya berbasis laporan dan alur kerja, bukan panel admin. tarif_ralan
| dipakai sebagai gerbang tunggal untuk seluruh area ini, konsisten dengan
| pola 'registrasi'/'resep_obat' yang menggerbangi satu area fungsional utuh,
| bukan satu aksi saja.
*/
Route::middleware(['web', 'auth', 'can:tarif_ralan'])->prefix('master')->name('master.')->group(function () {
    Route::get('/', [MasterDataController::class, 'index'])->name('index');

    Route::post('/penjamin', [MasterDataController::class, 'storePayer'])->name('penjamin.simpan');
    Route::post('/penjamin/{penjamin}', [MasterDataController::class, 'updatePayer'])->name('penjamin.perbarui');

    Route::post('/layanan', [MasterDataController::class, 'storeService'])->name('layanan.simpan');
    Route::post('/layanan/{layanan}', [MasterDataController::class, 'updateService'])->name('layanan.perbarui');

    Route::post('/tarif', [MasterDataController::class, 'storeTariff'])->name('tarif.simpan');
});
