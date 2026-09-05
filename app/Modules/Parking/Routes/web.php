<?php

use App\Modules\Parking\Http\Controllers\MasterDataController;
use App\Modules\Parking\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])
    ->prefix('parkir')
    ->name('parking.')
    ->group(function () {

        // parkir_jenis menaungi parkir_barcode: stok kartu didaftarkan admin
        // yang sama di layar yang sama, bukan oleh petugas gerbang.
        Route::middleware('can:parkir_jenis')->prefix('master')->name('master.')->group(function () {
            Route::get('/', [MasterDataController::class, 'index'])->name('index');
            Route::post('/tarif', [MasterDataController::class, 'storeRate'])->name('tarif.simpan');
            Route::post('/tarif/{tarif}', [MasterDataController::class, 'updateRate'])->name('tarif.perbarui');
            Route::post('/kartu', [MasterDataController::class, 'storeCard'])->name('kartu.simpan');
            Route::post('/kartu/{kartu}/nonaktifkan', [MasterDataController::class, 'deactivateCard'])->name('kartu.nonaktifkan');
        });

        // parkir_in. Sisi keluar ikut gerbang ini: petugasnya sama, dan
        // Khanza pun menyimpan masuk & keluar pada satu baris `parkir`
        // tanpa kode menu tersendiri untuk keluar.
        Route::middleware('can:parkir_in')->prefix('sesi')->name('sesi.')->group(function () {
            Route::get('/', [SessionController::class, 'index'])->name('index');
            Route::post('/', [SessionController::class, 'checkIn'])->name('masuk');
            Route::post('/{sesi}/keluar', [SessionController::class, 'checkOut'])->name('keluar');
        });

    });
