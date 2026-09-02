<?php

use App\Modules\Clinical\Http\Controllers\ClinicalRecordController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function () {
    Route::prefix('rme')->name('rme.')->group(function () {
        Route::get('/', [ClinicalRecordController::class, 'index'])->name('index');
        Route::get('/kunjungan/{registrasi}', [ClinicalRecordController::class, 'edit'])->name('edit');

        Route::post('/asesmen/{assessment}', [ClinicalRecordController::class, 'update'])->name('update');
        Route::post('/asesmen/{assessment}/finalkan', [ClinicalRecordController::class, 'finalize'])->name('finalkan');
        Route::post('/asesmen/{assessment}/diagnosis', [ClinicalRecordController::class, 'storeDiagnosis'])->name('diagnosis.simpan');
        Route::post('/asesmen/{assessment}/alergi', [ClinicalRecordController::class, 'storeAllergy'])->name('alergi.simpan');

        Route::delete('/diagnosis/{diagnosis}', [ClinicalRecordController::class, 'destroyDiagnosis'])->name('diagnosis.hapus');

        Route::get('/kode-diagnosis', [ClinicalRecordController::class, 'searchDiagnosisCodes'])->name('kode-diagnosis');
    })->middleware("can:penilaian_awal_medis_ralan");
});
