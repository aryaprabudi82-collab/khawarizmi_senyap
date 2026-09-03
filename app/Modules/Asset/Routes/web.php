<?php

use App\Modules\Asset\Http\Controllers\CssdController;
use App\Modules\Asset\Http\Controllers\MaintenanceController;
use App\Modules\Asset\Http\Controllers\MasterDataController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])
    ->prefix('aset')
    ->name('asset.')
    ->group(function () {

        Route::middleware('can:inventaris_inventaris')->group(function () {
            Route::get('/', [MasterDataController::class, 'index'])->name('index');
            Route::post('/aset', [MasterDataController::class, 'storeAsset'])->name('aset.simpan');
            Route::post('/aset/{aset}', [MasterDataController::class, 'updateAsset'])->name('aset.perbarui');
            Route::post('/kategori', [MasterDataController::class, 'storeCategory'])->name('kategori.simpan');
            Route::post('/lokasi', [MasterDataController::class, 'storeLocation'])->name('lokasi.simpan');
        });

        Route::middleware('can:perbaikan_inventaris')->prefix('pemeliharaan')->name('pemeliharaan.')->group(function () {
            Route::get('/', [MaintenanceController::class, 'index'])->name('index');
            Route::post('/', [MaintenanceController::class, 'store'])->name('simpan');
            Route::post('/{permintaan}/mulai', [MaintenanceController::class, 'start'])->name('mulai');
            Route::post('/{permintaan}/selesai', [MaintenanceController::class, 'complete'])->name('selesai');
            Route::post('/{permintaan}/tolak', [MaintenanceController::class, 'reject'])->name('tolak');
        });

        Route::middleware('can:sirkulasi_cssd')->prefix('cssd')->name('cssd.')->group(function () {
            Route::get('/', [CssdController::class, 'index'])->name('index');
            Route::post('/set', [CssdController::class, 'storeItem'])->name('set.simpan');
            Route::post('/terima', [CssdController::class, 'receive'])->name('terima');
            Route::post('/{sirkulasi}/proses', [CssdController::class, 'startProcessing'])->name('proses');
            Route::post('/{sirkulasi}/steril', [CssdController::class, 'markSterile'])->name('steril');
            Route::post('/{sirkulasi}/distribusi', [CssdController::class, 'distribute'])->name('distribusi');
        });

    });
