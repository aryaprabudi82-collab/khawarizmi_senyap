<?php

use App\Modules\Envlab\Http\Controllers\MasterDataController;
use App\Modules\Envlab\Http\Controllers\RecapController;
use App\Modules\Envlab\Http\Controllers\SampleTestController;
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

/*
| Alur transaksi: enam access-flag beda menggerbangi enam aksi berbeda
| (segregation-of-duties ala ISO 17025) — lihat catatan lengkap di migrasi
| envlab.sample_tests. index/show diperiksa imperatif
| (SampleTestController::assertCanView()) karena harus terbuka untuk siapa
| pun yang punya SALAH SATU dari keenamnya, bukan middleware 'can:' statis.
*/
Route::middleware(['web', 'auth'])->prefix('lab-kesling/pengujian')->name('envlab-tests.')->group(function () {
    Route::get('/', [SampleTestController::class, 'index'])->name('index');
    Route::get('/{pengujian}', [SampleTestController::class, 'show'])->name('show');

    Route::post('/', [SampleTestController::class, 'store'])
        ->name('simpan')->middleware('can:permintaan_pengujian_sampel_lab_kesehatan_lingkungan');
    Route::post('/{pengujian}/tolak', [SampleTestController::class, 'reject'])
        ->name('tolak')->middleware('can:permintaan_pengujian_sampel_lab_kesehatan_lingkungan');
    Route::post('/{pengujian}/terima', [SampleTestController::class, 'accept'])
        ->name('terima')->middleware('can:penugasan_pengujian_sampel_lab_kesehatan_lingkungan');
    Route::post('/item/{item}/hasil', [SampleTestController::class, 'storeResult'])
        ->name('hasil.simpan')->middleware('can:hasil_pengujian_sampel_lab_kesehatan_lingkungan');
    Route::post('/{pengujian}/verifikasi', [SampleTestController::class, 'verify'])
        ->name('verifikasi')->middleware('can:verifikasi_pengujian_sampel_lab_kesehatan_lingkungan');
    Route::post('/{pengujian}/validasi', [SampleTestController::class, 'validateResult'])
        ->name('validasi')->middleware('can:validasi_pengujian_sampel_lab_kesehatan_lingkungan');
    Route::post('/{pengujian}/bayar', [SampleTestController::class, 'markPaid'])
        ->name('bayar')->middleware('can:pembayaran_pengujian_sampel_lab_kesehatan_lingkungan');
});

/*
| rekap_pelayanan_lab_kesehatan_lingkungan — satu-satunya kode rekap yang
| genuinely punya access flag di sumber Khanza, lihat catatan kelas
| RecapController soal rekap pembayaran yang digabung ke sini.
*/
Route::middleware(['web', 'auth', 'can:rekap_pelayanan_lab_kesehatan_lingkungan'])
    ->prefix('lab-kesling/rekap')->name('envlab-recap.')->group(function () {
        Route::get('/', [RecapController::class, 'index'])->name('index');
    });
