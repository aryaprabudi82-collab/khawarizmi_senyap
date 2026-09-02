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
});
