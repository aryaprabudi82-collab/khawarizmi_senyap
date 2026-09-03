<?php

use App\Modules\Correspondence\Http\Controllers\AnnouncementController;
use App\Modules\Correspondence\Http\Controllers\LetterController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])
    ->prefix('surat')
    ->name('correspondence.')
    ->group(function () {

        Route::middleware('can:surat_masuk')->group(function () {
            Route::get('/', [LetterController::class, 'index'])->name('index');
            Route::post('/masuk', [LetterController::class, 'storeIncoming'])->name('masuk.simpan');
            Route::post('/masuk/{surat}/disposisi', [LetterController::class, 'disposition'])->name('masuk.disposisi');
            Route::post('/masuk/{surat}/arsip', [LetterController::class, 'archiveIncoming'])->name('masuk.arsip');
            Route::post('/keluar', [LetterController::class, 'storeOutgoing'])->name('keluar.simpan');
            Route::post('/keluar/{surat}/kirim', [LetterController::class, 'send'])->name('keluar.kirim');
        });

        Route::middleware('can:pengumuman_epasien')->prefix('pengumuman')->name('pengumuman.')->group(function () {
            Route::get('/', [AnnouncementController::class, 'index'])->name('index');
            Route::post('/', [AnnouncementController::class, 'store'])->name('simpan');
            Route::post('/{pengumuman}', [AnnouncementController::class, 'update'])->name('perbarui');
        });

    });
