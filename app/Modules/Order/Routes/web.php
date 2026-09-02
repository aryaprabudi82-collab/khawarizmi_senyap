<?php

use App\Modules\Order\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

/*
| {kategori} dibatasi ke 'lab'|'radiologi' lewat where(). Otorisasinya
| berbeda per nilai (periksa_lab vs periksa_radiologi), jadi ditegakkan di
| dalam OrderController::assertAccess(), bukan lewat middleware 'can:' di
| sini — middleware itu tidak bisa membaca nilai parameter rute saat
| menentukan permission mana yang diperiksa.
*/
Route::middleware(['web', 'auth'])
    ->prefix('order/{kategori}')
    ->where(['kategori' => 'lab|radiologi'])
    ->name('order.')
    ->group(function () {
        Route::get('/', [OrderController::class, 'index'])->name('index');
        Route::get('/cari', [OrderController::class, 'searchCatalog'])->name('cari');
        Route::get('/{order}', [OrderController::class, 'show'])->name('show');

        Route::post('/kunjungan/{registrasi}', [OrderController::class, 'createForRegistration'])->name('buat');
        Route::post('/{order}/item', [OrderController::class, 'storeItem'])->name('item.simpan');
        Route::delete('/item/{item}', [OrderController::class, 'destroyItem'])->name('item.hapus');
        Route::post('/{order}/proses', [OrderController::class, 'startProcessing'])->name('proses');
        Route::post('/item/{item}/hasil', [OrderController::class, 'storeResult'])->name('hasil.simpan');
        Route::post('/{order}/verifikasi', [OrderController::class, 'verify'])->name('verifikasi');
        Route::post('/{order}/batal', [OrderController::class, 'cancel'])->name('batal');
    });
