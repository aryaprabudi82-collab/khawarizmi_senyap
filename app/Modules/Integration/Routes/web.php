<?php

use App\Modules\Integration\Http\Controllers\BpjsController;
use App\Modules\Integration\Http\Controllers\IntegrationSettingController;
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

        /*
        | Pengaturan kredensial integrasi — "rumah" sistem luar.
        |
        | Digerbangi TERSENDIRI lewat aplikasi (Set Aplikasi, domain U),
        | yang sudah dipegang peran admin-sistem — bukan ikut
        | gerbang pemakaian seperti bpjs_cek_kartu: yang memakai
        | integrasi setiap hari (petugas loket, rekam medis) tidak
        | seharusnya bisa mengubah kredensial rumah sakit, dan yang
        | memasang kredensial belum tentu perlu mengakses data pasien.
        */
        Route::middleware('can:aplikasi')->prefix('pengaturan')->name('pengaturan.')->group(function () {
            Route::get('/', [IntegrationSettingController::class, 'index'])->name('index');
            Route::post('/{system}', [IntegrationSettingController::class, 'update'])->name('simpan');
            Route::post('/{system}/uji', [IntegrationSettingController::class, 'check'])->name('uji');
            Route::post('/{system}/{field}/hapus', [IntegrationSettingController::class, 'forget'])->name('hapus');
        });

        Route::prefix('satusehat')->name('satusehat.')->group(function () {
            Route::get('/', [SatusehatController::class, 'index'])->name('index')->middleware('can:satu_sehat_referensi_pasien');
            Route::post('/pemetaan-lokasi', [SatusehatController::class, 'mapLocation'])->name('pemetaan-lokasi')->middleware('can:satu_sehat_mapping_lokasi');
            Route::post('/pemetaan-praktisi', [SatusehatController::class, 'mapPractitioner'])->name('pemetaan-praktisi')->middleware('can:satu_sehat_referensi_dokter');
            Route::post('/kunjungan/{registrasi}/sinkron', [SatusehatController::class, 'syncEncounter'])->name('kunjungan.sinkron')->middleware('can:satu_sehat_kirim_encounter');
            Route::post('/kunjungan/{registrasi}/diagnosis/sinkron', [SatusehatController::class, 'syncCondition'])->name('diagnosis.sinkron')->middleware('can:satu_sehat_kirim_condition');
        });

    });
