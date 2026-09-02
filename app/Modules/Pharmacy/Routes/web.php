<?php

use App\Modules\Pharmacy\Http\Controllers\PrescriptionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function () {
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
