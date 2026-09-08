<?php

use App\Modules\Library\Http\Controllers\CatalogController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])
    ->prefix('perpustakaan')
    ->name('library.')
    ->group(function () {

        /*
         * Katalog koleksi — digerbangi `koleksi_perpustakaan` sebagai
         * umbrella untuk ebook_perpustakaan juga: keduanya satu tabel dengan
         * medium berbeda, jadi memisahkan gerbangnya akan melahirkan
         * kewenangan yang tidak bisa ditegakkan tanpa menyaring baris.
         *
         * Tiga baris katalog Khanza yang TIDAK punya access flag ("Koleksi
         * Penelitian", "Cari Koleksi Ebook", "Cari Inventaris
         * Perpustakaan") dilayani penyaring pada layar ini — ketiganya
         * memang pencarian atas data yang sama, bukan layar tersendiri.
         */
        Route::middleware('can:koleksi_perpustakaan')->group(function () {
            Route::get('/', [CatalogController::class, 'index'])->name('index');
            Route::post('/koleksi', [CatalogController::class, 'store'])->name('koleksi.simpan');
        });

        /*
         * Master katalog: ruang, kategori, jenis, pengarang, penerbit — lima
         * kode Khanza di satu layar, digerbangi `kategori_perpustakaan`
         * sebagai umbrella. Pola yang sama dengan master data envlab dan
         * farmasi: kelimanya dikelola pustakawan yang sama, dan memecah
         * gerbangnya melahirkan kewenangan yang tidak dipegang siapa pun.
         */
        Route::middleware('can:kategori_perpustakaan')->prefix('master')->name('master.')->group(function () {
            Route::get('/', [CatalogController::class, 'master'])->name('index');
            Route::post('/', [CatalogController::class, 'storeMaster'])->name('simpan');
        });

    });
