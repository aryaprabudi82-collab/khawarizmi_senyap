<?php

use App\Modules\Encounter\Http\Controllers\BarcodeController;
use App\Modules\Encounter\Http\Controllers\CorporateMcuBookingController;
use App\Modules\Encounter\Http\Controllers\IgdController;
use App\Modules\Encounter\Http\Controllers\KfrProgramRequestController;
use App\Modules\Encounter\Http\Controllers\OperationBookingController;
use App\Modules\Encounter\Http\Controllers\QueueDisplayController;
use App\Modules\Encounter\Http\Controllers\ReferralController;
use App\Modules\Encounter\Http\Controllers\RegistrationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function () {
    Route::prefix('registrasi')->name('registrasi.')->group(function () {
        Route::get('/', [RegistrationController::class, 'index'])->name('index')->can('registrasi');
        Route::get('/baru', [RegistrationController::class, 'create'])->name('create')->can('registrasi');
        Route::post('/', [RegistrationController::class, 'store'])->name('store')->can('registrasi');
        Route::post('/{registrasi}/batal', [RegistrationController::class, 'cancel'])->name('batal')->can('registrasi');

        // barcoderalan/barcoderanap — permission-nya beda per jenis rawat,
        // diperiksa imperatif di BarcodeController::print(), bukan lewat
        // middleware 'can:' statis di sini. Lihat catatan kelasnya.
        Route::get('/{registrasi}/barcode', [BarcodeController::class, 'print'])->name('barcode');
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

    // igd — gerbang sendiri, sengaja dikecualikan dari petugas-daftar (lihat
    // roles.json): registrasi & triase IGD ditangani staf klinis (dokter/
    // perawat), bukan loket rawat jalan biasa.
    Route::middleware('can:igd')->prefix('igd')->name('igd.')->group(function () {
        Route::get('/', [IgdController::class, 'index'])->name('index');
        Route::post('/', [IgdController::class, 'register'])->name('daftar');
        Route::post('/{registrasi}/triase', [IgdController::class, 'triage'])->name('triase');
    });

    // booking_mcu_perusahaan — pemesanan MCU massal oleh perusahaan, belum
    // tentu ada pasien terdaftar. Gerbang sendiri (bukan turunan registrasi)
    // supaya kelak mudah dipisah dari registrasi biasa, walau Wave 1 ini
    // sama-sama diwariskan petugas-daftar lewat context encounter.
    Route::middleware('can:booking_mcu_perusahaan')->prefix('mcu-perusahaan')->name('mcu-perusahaan.')->group(function () {
        Route::get('/', [CorporateMcuBookingController::class, 'index'])->name('index');
        Route::post('/', [CorporateMcuBookingController::class, 'store'])->name('simpan');
        Route::post('/{booking}/selesai', [CorporateMcuBookingController::class, 'complete'])->name('selesai');
        Route::post('/{booking}/batal', [CorporateMcuBookingController::class, 'cancel'])->name('batal');
    });

    // booking_operasi — jadwal operasi untuk kunjungan yang sudah terdaftar,
    // lihat catatan migrasi soal bedanya dari booking_registrasi/booking_periksa.
    Route::middleware('can:booking_operasi')->prefix('booking-operasi')->name('booking-operasi.')->group(function () {
        Route::get('/', [OperationBookingController::class, 'index'])->name('index');
        Route::post('/', [OperationBookingController::class, 'store'])->name('simpan');
        Route::post('/{booking}/selesai', [OperationBookingController::class, 'complete'])->name('selesai');
        Route::post('/{booking}/batal', [OperationBookingController::class, 'cancel'])->name('batal');
    });

    // layanan_program_kfr — permintaan rujukan ke program KFR, keputusan
    // klinis dokter, pola sama dengan rujukan_keluar.
    Route::middleware('can:layanan_program_kfr')->prefix('program-kfr')->name('program-kfr.')->group(function () {
        Route::get('/', [KfrProgramRequestController::class, 'index'])->name('index');
        Route::post('/', [KfrProgramRequestController::class, 'store'])->name('simpan');
        Route::post('/{permintaan}/batal', [KfrProgramRequestController::class, 'cancel'])->name('batal');
    });
});

/*
 * Layar antrean pendaftaran & poliklinik (Khanza `display`, domain U).
 *
 * DI LUAR grup layar kerja: ini halaman yang menghadap ruang tunggu, dipasang
 * di TV dan tidak ada yang menekan tombol di depannya. Tata letaknya pun
 * berbeda sepenuhnya — layout `display`, bukan `app`.
 *
 * TIDAK ADA TABEL BARU untuk kode ini. Nomor antrean dan statusnya sudah
 * tercatat pada encounter.registrations sejak konteks ini dibangun; membuat
 * tabel antrean terpisah akan melahirkan dua sumber kebenaran yang bisa
 * berbeda tentang siapa yang sedang dipanggil.
 *
 * TETAP DIGERBANGI. Kios yang menampilkannya memakai akunnya sendiri dengan
 * satu kapabilitas ini saja: layar yang bisa dibuka tanpa masuk berarti
 * daftar pasien hari ini bisa dibaca siapa pun yang menebak alamatnya, dan
 * penyamaran nama tidak menutup itu — nomor antrean berikut nama poliklinik
 * sudah cukup untuk mencocokkan orang yang terlihat masuk.
 */
Route::middleware(['web', 'auth', 'can:display'])
    ->get('/antrean/display', [QueueDisplayController::class, 'index'])
    ->name('antrean.display');
