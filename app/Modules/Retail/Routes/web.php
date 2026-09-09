<?php

use App\Modules\Retail\Http\Controllers\ProcurementController;
use App\Modules\Retail\Http\Controllers\ProductController;
use App\Modules\Retail\Http\Controllers\SalesController;
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

        /*
         * Rantai pengadaan. `toko_pengadaan_barang` menggerbangi layarnya dan
         * menaungi `toko_pengajuan_barang`, `toko_surat_pemesanan`,
         * `toko_penerimaan_barang`, `toko_retur_beli`, `toko_hutang`, dan
         * `toko_bayar_pemesanan`: seluruh rantai dikerjakan orang yang sama
         * di toko sebesar koperasi rumah sakit, dan memecah gerbangnya
         * melahirkan kewenangan yang tidak dipegang siapa pun. Surat
         * pemesanan sendiri bukan entitas kedua — ia tampilan cetak pesanan
         * yang sama, pola yang persis dipakai `pemesanan_obat` domain D.
         */
        Route::middleware('can:toko_pengadaan_barang')->prefix('pengadaan')->name('pengadaan.')->group(function () {
            Route::get('/', [ProcurementController::class, 'index'])->name('index');
            Route::post('/pengajuan', [ProcurementController::class, 'storeRequisition'])->name('pengajuan.simpan');
            Route::post('/pengajuan/{pengajuan}/putuskan', [ProcurementController::class, 'decideRequisition'])->name('pengajuan.putuskan');
            Route::post('/pesanan', [ProcurementController::class, 'storeOrder'])->name('pesanan.simpan');
            Route::post('/pesanan/{pesanan}/kirim', [ProcurementController::class, 'sendOrder'])->name('pesanan.kirim');
            Route::get('/pesanan/{pesanan}/surat', [ProcurementController::class, 'printOrder'])->name('pesanan.surat');
            Route::post('/pesanan/{pesanan}/terima', [ProcurementController::class, 'receive'])->name('pesanan.terima');
            Route::post('/penerimaan/{penerimaan}/bayar', [ProcurementController::class, 'pay'])->name('penerimaan.bayar');
            Route::post('/retur', [ProcurementController::class, 'storeReturn'])->name('retur.simpan');
        });

        /*
         * Kasir & piutang. `toko_penjualan` menggerbangi layarnya dan
         * menaungi `toko_member`, `toko_retur_jual`, `toko_piutang`,
         * `toko_retur_piutang`, `toko_bayar_piutang`, serta empat kode rekap
         * (pendapatan harian, penjualan harian, piutang harian, keuntungan
         * barang). Keempat rekap itu membaca data yang sama dari sudut
         * berbeda — empat layar berarti empat tempat yang bisa berbeda
         * jawabannya untuk hari yang sama.
         */
        Route::middleware('can:toko_penjualan')->prefix('penjualan')->name('penjualan.')->group(function () {
            Route::get('/', [SalesController::class, 'index'])->name('index');
            Route::post('/', [SalesController::class, 'store'])->name('simpan');
            Route::post('/member', [SalesController::class, 'storeMember'])->name('member.simpan');
            Route::post('/{penjualan}/bayar', [SalesController::class, 'pay'])->name('bayar');
            Route::post('/{penjualan}/retur', [SalesController::class, 'storeReturn'])->name('retur');
        });

    });
