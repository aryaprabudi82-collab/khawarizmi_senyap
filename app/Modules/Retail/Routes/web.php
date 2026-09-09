<?php

use App\Modules\Retail\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])
    ->prefix('toko')
    ->name('retail.')
    ->group(function () {

        /*
         * Barang, master, dan harga jual. `toko_barang` menggerbangi
         * layarnya dan menaungi `toko_jenis` serta `toko_suplier` — ketiganya
         * dikelola orang yang sama, dan memecah gerbangnya melahirkan
         * kewenangan yang tidak dipegang siapa pun. Pola yang sama dengan
         * master data farmasi dan envlab.
         */
        Route::middleware('can:toko_barang')->group(function () {
            Route::get('/', [ProductController::class, 'index'])->name('index');
            Route::post('/barang', [ProductController::class, 'store'])->name('barang.simpan');
            Route::post('/master', [ProductController::class, 'storeMaster'])->name('master.simpan');
            Route::post('/barang/{barang}/harga', [ProductController::class, 'setPrice'])->name('harga.simpan');
            Route::post('/patokan-marjin', [ProductController::class, 'setPricingPolicy'])->name('patokan.simpan');
        });

        /*
         * Stok, opname, riwayat & sirkulasi. `stok_opname_toko`
         * menggerbangi layarnya dan menaungi tiga kode pembacaan
         * (toko_riwayat_barang, toko_sirkulasi, toko_sirkulasi2) — ketiganya
         * membaca buku besar yang sama dengan penyaring berbeda, dan tiga
         * layar berarti tiga tempat memperbaiki satu kesalahan yang sama.
         */
        Route::middleware('can:stok_opname_toko')->prefix('stok')->name('stok.')->group(function () {
            Route::get('/', [ProductController::class, 'stock'])->name('index');
            Route::post('/opname', [ProductController::class, 'openOpname'])->name('opname.buka');
            Route::post('/opname/baris/{baris}', [ProductController::class, 'recordCount'])->name('opname.hitung');
            Route::post('/opname/{opname}/tutup', [ProductController::class, 'completeOpname'])->name('opname.tutup');
        });

    });
