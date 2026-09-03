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

        // sekrining_rawat_jalan — satu layar dengan asesmen, gerbang umbrella
        // yang sama (penilaian_awal_medis_ralan), bukan kode terpisah: kedua
        // aktivitas dilakukan staf klinis yang sama (dokter/perawat), lihat
        // catatan migrasi clinical.screenings.
        Route::post('/kunjungan/{registrasi}/skrining', [ClinicalRecordController::class, 'storeScreening'])->name('skrining.simpan');

        // tindakan_ralan — gerbang umbrella yang sama, lihat catatan migrasi
        // clinical.procedures.
        Route::post('/kunjungan/{registrasi}/tindakan', [ClinicalRecordController::class, 'storeProcedure'])->name('tindakan.simpan');

        // operasi — gerbang SENDIRI (bukan umbrella), ditumpuk di atas
        // penilaian_awal_medis_ralan milik grup: mencatat operasi tetap
        // butuh akses RME kunjungan, tapi kapabilitasnya sendiri dibatasi
        // ke yang punya operasi (dokter, lewat wholesale context encounter —
        // dikecualikan dari perawat/petugas-daftar, lihat roles.json), beda
        // dari tindakan_ralan yang cukup umbrella. Lihat catatan migrasi
        // clinical.operations.
        Route::post('/kunjungan/{registrasi}/operasi', [ClinicalRecordController::class, 'storeOperation'])
            ->name('operasi.simpan')->middleware('can:operasi');

        Route::delete('/diagnosis/{diagnosis}', [ClinicalRecordController::class, 'destroyDiagnosis'])->name('diagnosis.hapus');

        Route::get('/kode-diagnosis', [ClinicalRecordController::class, 'searchDiagnosisCodes'])->name('kode-diagnosis');
    })->middleware("can:penilaian_awal_medis_ralan");
});
