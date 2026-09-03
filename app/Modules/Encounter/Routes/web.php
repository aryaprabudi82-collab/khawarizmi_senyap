<?php

use App\Modules\Encounter\Http\Controllers\ReferralController;
use App\Modules\Encounter\Http\Controllers\RegistrationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function () {
    Route::prefix('registrasi')->name('registrasi.')->group(function () {
        Route::get('/', [RegistrationController::class, 'index'])->name('index')->can('registrasi');
        Route::get('/baru', [RegistrationController::class, 'create'])->name('create')->can('registrasi');
        Route::post('/', [RegistrationController::class, 'store'])->name('store')->can('registrasi');
        Route::post('/{registrasi}/batal', [RegistrationController::class, 'cancel'])->name('batal')->can('registrasi');
    });

    // rujukan_keluar — keputusan klinis dokter merujuk pasien ke faskes
    // lain, gerbangnya sendiri (bukan umbrella registrasi), pola sama
    // dengan persetujuan_penolakan_tindakan/surat_keterangan_sehat.
    Route::middleware('can:rujukan_keluar')->prefix('rujukan-keluar')->name('rujukan-keluar.')->group(function () {
        Route::get('/', [ReferralController::class, 'index'])->name('index');
        Route::post('/', [ReferralController::class, 'store'])->name('simpan');
        Route::post('/{rujukan}/batal', [ReferralController::class, 'cancel'])->name('batal');
        Route::get('/{rujukan}/cetak', [ReferralController::class, 'print'])->name('cetak');
    });
});
