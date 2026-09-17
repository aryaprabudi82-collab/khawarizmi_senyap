<?php

use App\Modules\Organization\Http\Controllers\MasterDataController;
use App\Modules\Organization\Http\Controllers\StaffDirectoryController;
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

/*
 * Daftar pegawai & tenaga kesehatan (layar baca).
 *
 * TERPISAH DARI master.organisasi yang mengelola unit, praktisi, dan jadwal
 * sekaligus. Layar ini menjawab satu pertanyaan yang jauh lebih sering
 * ditanyakan — "siapa saja yang bekerja di sini, di unit mana, sebagai apa" —
 * dan menjawabnya atas 1.524 baris, yang menuntut pencarian serta paginasi
 * alih-alih satu tabel panjang.
 *
 * Gerbangnya `tarif_ralan`, sama dengan master organisasi — daftar ketenagaan
 * adalah data master, dan yang mengelolanya admin data master.
 *
 * BUKAN `dokter` meski kode Khanza itu terdengar paling cocok: permission
 * tersebut ada di katalog tapi tidak diberikan ke satu peran pun, sehingga
 * layarnya tidak akan bisa dibuka siapa pun. Gerbang yang benar adalah yang
 * sungguh dimiliki seseorang, bukan yang paling tepat namanya.
 *
 * BUKAN PULA `pegawai_user`: itu menaungi kepegawaian dan penggajian yang
 * memuat data jauh lebih sensitif daripada daftar nama dan unit kerja.
 */
Route::middleware(['web', 'auth', 'can:tarif_ralan'])->group(function () {
    Route::get('/pegawai', [StaffDirectoryController::class, 'index'])->name('pegawai.index');
    Route::post('/pegawai/{pegawai}', [StaffDirectoryController::class, 'update'])->name('pegawai.perbarui');
});
