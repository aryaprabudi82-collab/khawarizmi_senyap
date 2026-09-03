<?php

use App\Modules\Envlab\Http\Controllers\MasterDataController;
use Illuminate\Support\Facades\Route;

/*
| Data master lab kesling (pelanggan, jenis sampel, parameter, baku mutu)
| digabung satu layar dan satu gerbang (pelanggan_lab_kesehatan_lingkungan),
| pola sama dengan organization::master.index yang menggabung unit/
| praktisi/jadwal di bawah gerbang tarif_ralan — dikerjakan petugas lab
| kesling yang sama, bukan empat peran berbeda. Tiga kode Khanza lain
| (master_sampel_bakumutu, parameter_pengujian_lab_kesehatan_lingkungan,
| nilai_normal_baku_mutu_lab_kesehatan_lingkungan) SENGAJA tidak didaftarkan
| sebagai gerbang literal terpisah karena itu.
*/
Route::middleware(['web', 'auth', 'can:pelanggan_lab_kesehatan_lingkungan'])
    ->prefix('lab-kesling/master')->name('envlab-master.')->group(function () {
        Route::get('/', [MasterDataController::class, 'index'])->name('index');
        Route::post('/pelanggan', [MasterDataController::class, 'storeCustomer'])->name('pelanggan.simpan');
        Route::post('/jenis-sampel', [MasterDataController::class, 'storeSampleType'])->name('jenis-sampel.simpan');
        Route::post('/parameter', [MasterDataController::class, 'storeParameter'])->name('parameter.simpan');
        Route::post('/baku-mutu', [MasterDataController::class, 'storeQualityStandard'])->name('baku-mutu.simpan');
    });
