<?php

use App\Modules\Inpatient\Http\Controllers\AdmissionController;
use App\Modules\Inpatient\Http\Controllers\RoomController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])
    ->prefix('rawat-inap')
    ->name('inpatient.')
    ->group(function () {

        // Gerbang tunggal tindakan_ranap untuk seluruh modul — sama pola
        // dengan pegawai_user di hr, lihat catatan migrasi inpatient.
        Route::middleware('can:tindakan_ranap')->group(function () {
            Route::get('/', [AdmissionController::class, 'index'])->name('index');
            Route::post('/admisi', [AdmissionController::class, 'store'])->name('admisi.simpan');
            Route::post('/admisi/{admisi}/pulang', [AdmissionController::class, 'discharge'])->name('admisi.pulang');

            Route::prefix('kamar')->name('kamar.')->group(function () {
                Route::get('/', [RoomController::class, 'index'])->name('index');
                Route::post('/', [RoomController::class, 'store'])->name('simpan');
                Route::post('/{kamar}', [RoomController::class, 'update'])->name('perbarui');
                Route::post('/{kamar}/bed', [RoomController::class, 'addBed'])->name('bed.simpan');
                Route::post('/bed/{bed}/bersih', [RoomController::class, 'markClean'])->name('bed.bersih');
                Route::post('/bed/{bed}/nonaktifkan', [RoomController::class, 'deactivateBed'])->name('bed.nonaktifkan');
                Route::post('/bed/{bed}/aktifkan', [RoomController::class, 'reactivateBed'])->name('bed.aktifkan');
            });
        });

    });
