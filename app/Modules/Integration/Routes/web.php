<?php

use App\Modules\Integration\Http\Controllers\BpjsController;
use App\Modules\Integration\Http\Controllers\SatusehatController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])
    ->prefix('integrasi')
    ->name('integrasi.')
    ->group(function () {

        Route::prefix('bpjs')->name('bpjs.')->group(function () {
            Route::get('/', [BpjsController::class, 'index'])->name('index')->middleware('can:bpjs_cek_kartu');
            Route::post('/cek-kartu', [BpjsController::class, 'checkEligibility'])->name('cek-kartu')->middleware('can:bpjs_cek_kartu');
            Route::post('/sep/{registrasi}', [BpjsController::class, 'storeSep'])->name('sep.simpan')->middleware('can:bpjs_sep');
            Route::post('/sep/{sep}/batal', [BpjsController::class, 'cancelSep'])->name('sep.batal')->middleware('can:bpjs_sep');
            Route::post('/pemetaan-poli', [BpjsController::class, 'mapPoli'])->name('pemetaan-poli')->middleware('can:mapping_poli_bpjs');
        });

        Route::prefix('satusehat')->name('satusehat.')->group(function () {
            Route::get('/', [SatusehatController::class, 'index'])->name('index')->middleware('can:satu_sehat_referensi_pasien');
            Route::post('/pemetaan-lokasi', [SatusehatController::class, 'mapLocation'])->name('pemetaan-lokasi')->middleware('can:satu_sehat_mapping_lokasi');
            Route::post('/pemetaan-praktisi', [SatusehatController::class, 'mapPractitioner'])->name('pemetaan-praktisi')->middleware('can:satu_sehat_referensi_dokter');
            Route::post('/kunjungan/{registrasi}/sinkron', [SatusehatController::class, 'syncEncounter'])->name('kunjungan.sinkron')->middleware('can:satu_sehat_kirim_encounter');
            Route::post('/kunjungan/{registrasi}/diagnosis/sinkron', [SatusehatController::class, 'syncCondition'])->name('diagnosis.sinkron')->middleware('can:satu_sehat_kirim_condition');
        });

    });
