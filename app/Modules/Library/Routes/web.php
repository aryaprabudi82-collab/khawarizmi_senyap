<?php

use App\Modules\Library\Http\Controllers\CatalogController;
use App\Modules\Library\Http\Controllers\CirculationController;
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

        // Eksemplar fisik. Gerbangnya sendiri: yang mengatalogkan judul dan
        // yang menomori eksemplar bisa orang berbeda di perpustakaan besar.
        Route::middleware('can:inventaris_perpustakaan')->prefix('eksemplar')->name('eksemplar.')->group(function () {
            Route::get('/', [CirculationController::class, 'items'])->name('index');
            Route::post('/', [CirculationController::class, 'storeItem'])->name('simpan');
            Route::post('/{eksemplar}/kondisi', [CirculationController::class, 'updateItemCondition'])->name('kondisi');
        });

        Route::middleware('can:anggota_perpustakaan')->prefix('anggota')->name('anggota.')->group(function () {
            Route::get('/', [CirculationController::class, 'members'])->name('index');
            Route::post('/', [CirculationController::class, 'storeMember'])->name('simpan');
        });

        /*
         * Meja sirkulasi: pinjam, kembali, dan penyelesaian denda.
         * `peminjaman_perpustakaan` menaungi `bayar_denda_perpustakaan` —
         * denda diselesaikan di meja yang sama saat buku dikembalikan, dan
         * memisahkan gerbangnya melahirkan kewenangan yang tidak dipegang
         * siapa pun.
         */
        Route::middleware('can:peminjaman_perpustakaan')->prefix('sirkulasi')->name('sirkulasi.')->group(function () {
            Route::get('/', [CirculationController::class, 'index'])->name('index');
            Route::post('/pinjam', [CirculationController::class, 'borrow'])->name('pinjam');
            Route::post('/{pinjaman}/kembali', [CirculationController::class, 'returnItem'])->name('kembali');
            Route::post('/denda/{denda}', [CirculationController::class, 'settleFine'])->name('denda.selesai');
        });

        /*
         * Pengaturan peminjaman & jenis denda — `set_peminjaman_perpustakaan`
         * menaungi `denda_perpustakaan`: keduanya angka kebijakan yang
         * ditetapkan orang yang sama, bukan pekerjaan harian meja sirkulasi.
         */
        Route::middleware('can:set_peminjaman_perpustakaan')->prefix('pengaturan')->name('pengaturan.')->group(function () {
            Route::get('/', [CirculationController::class, 'settings'])->name('index');
            Route::post('/', [CirculationController::class, 'storeSettings'])->name('simpan');
            Route::post('/denda', [CirculationController::class, 'storeFineType'])->name('denda.simpan');
        });

    });
