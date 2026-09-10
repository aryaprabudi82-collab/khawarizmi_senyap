<?php

use App\Modules\Organization\Http\Controllers\MasterDataController;
use Illuminate\Support\Facades\Route;

// Satu gerbang dengan konteks catalog (tarif_ralan) - lihat catatan di
// app/Modules/Catalog/Routes/web.php.
Route::middleware(['web', 'auth', 'can:tarif_ralan'])->prefix('master')->name('master.')->group(function () {
    Route::get('/organisasi', [MasterDataController::class, 'index'])->name('organisasi');

    Route::post('/unit', [MasterDataController::class, 'storeUnit'])->name('unit.simpan');
    Route::post('/unit/{unit}', [MasterDataController::class, 'updateUnit'])->name('unit.perbarui');

    Route::post('/praktisi', [MasterDataController::class, 'storePractitioner'])->name('praktisi.simpan');
    Route::post('/praktisi/{praktisi}', [MasterDataController::class, 'updatePractitioner'])->name('praktisi.perbarui');

    Route::post('/praktisi/{praktisi}/jadwal', [MasterDataController::class, 'storeSchedule'])->name('praktisi.jadwal.simpan');
    Route::delete('/jadwal/{jadwal}', [MasterDataController::class, 'destroySchedule'])->name('jadwal.hapus');
});

/*
 * Penanggung jawab unit penunjang (Khanza `setup_pjlab`, domain U).
 *
 * Gerbangnya sendiri: penetapan penanggung jawab unit penunjang adalah
 * keputusan struktural yang bersandar SK direktur, bukan pengelolaan data
 * master sehari-hari.
 */
Route::middleware(['web', 'auth', 'can:setup_pjlab'])->prefix('master')->name('master.')->group(function () {
    Route::get('/penanggung-jawab', [MasterDataController::class, 'supervisors'])->name('penanggung-jawab');
    Route::post('/penanggung-jawab', [MasterDataController::class, 'storeSupervisor'])->name('penanggung-jawab.simpan');
});

/*
 * Master ruang operasi (Khanza `ruang_ok`, domain U).
 *
 * Gerbangnya sendiri, TIDAK dilebur ke `tarif_ralan` seperti master
 * organisasi lainnya: yang menyusun daftar kamar operasi adalah instalasi
 * bedah, bukan orang yang menetapkan tarif rawat jalan — dan Khanza pun
 * memberinya kode tersendiri.
 */
Route::middleware(['web', 'auth', 'can:ruang_ok'])->prefix('master')->name('master.')->group(function () {
    Route::get('/ruang-operasi', [MasterDataController::class, 'operatingRooms'])->name('ruang-operasi');
    Route::post('/ruang-operasi', [MasterDataController::class, 'storeOperatingRoom'])->name('ruang-operasi.simpan');
    Route::post('/ruang-operasi/{ruang}', [MasterDataController::class, 'updateOperatingRoom'])->name('ruang-operasi.perbarui');
});
