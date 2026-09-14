<?php

use App\Modules\Keuangan\MasterData\Http\Controllers\MasterKeuanganController;
use Illuminate\Support\Facades\Route;

/*
 * Layar master keuangan — Modul A.
 *
 * SELURUHNYA DIGERBANGI `pendapatan_per_akun`, dipegang Petugas Keuangan
 * dan Manajemen RS. Pemetaan item ke akun COA adalah keputusan akuntansi,
 * jadi ia memang milik keuangan — bukan milik administrator sistem yang
 * tidak tahu akun mana yang menampung pendapatan tindakan.
 *
 * Prefiks `master-keuangan`, bukan `keuangan`, supaya tidak menabrak
 * `keuangan.index` milik Pusat Keuangan yang sudah ada. Dua layar berbeda
 * dengan nama rute sama akan membuat salah satunya tidak pernah terbuka,
 * dan yang menang ditentukan urutan pemuatan modul — bukan oleh siapa pun.
 */
Route::middleware(['web', 'auth', 'can:pendapatan_per_akun'])
    ->prefix('master-keuangan')
    ->name('master-keuangan.')
    ->group(function () {
        Route::get('/', [MasterKeuanganController::class, 'index'])->name('index');

        // Charge Description Master
        Route::get('/item', [MasterKeuanganController::class, 'chargeMaster'])->name('item');
        Route::post('/item/tautkan', [MasterKeuanganController::class, 'tautkan'])->name('item.tautkan');
        Route::post('/item/{item}/petakan', [MasterKeuanganController::class, 'petakanAkun'])->name('item.petakan');
        Route::post('/item/{item}/aktifkan', [MasterKeuanganController::class, 'aktifkan'])->name('item.aktifkan');
        Route::post('/item/{item}/expire', [MasterKeuanganController::class, 'expire'])->name('item.expire');

        // Kontrak penjamin
        Route::get('/kontrak', [MasterKeuanganController::class, 'kontrakPenjamin'])->name('kontrak');
        Route::post('/kontrak', [MasterKeuanganController::class, 'simpanKontrak'])->name('kontrak.simpan');

        // Pusat biaya & pusat pendapatan
        Route::get('/pusat-biaya', [MasterKeuanganController::class, 'pusatBiaya'])->name('pusat-biaya');
        Route::post('/pusat-biaya', [MasterKeuanganController::class, 'simpanPusatBiaya'])->name('pusat-biaya.simpan');
    });
