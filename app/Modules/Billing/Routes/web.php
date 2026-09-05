<?php

use App\Modules\Billing\Http\Controllers\InvoiceController;
use App\Modules\Billing\Http\Controllers\ReceivableController;
use Illuminate\Support\Facades\Route;

/*
| Tidak ada gerbang can: di tingkat rute.
|
| Khanza memisahkan kasir rawat jalan (pembayaran_ralan) dan rawat inap
| (pembayaran_ranap), sementara satu layar tagihan di sini melayani
| keduanya — middleware can: hanya menerima satu kode, jadi memasangnya
| berarti mengunci salah satu kasir dari layarnya sendiri. Otorisasinya
| dipindah ke InvoiceController::assertAccess(), diperiksa per tagihan
| berdasarkan kolom care_type: pola yang sama dipakai
| OrderController::assertAccess() untuk lab/radiologi/PA yang juga berbagi
| satu layar. Daftar tagihan ikut disaring ke jenis rawat yang boleh
| dilihat pengguna, dan pengguna tanpa kedua permission mendapat 403.
*/
Route::middleware(['web', 'auth'])->group(function () {
    Route::prefix('tagihan')->name('tagihan.')->group(function () {
        Route::get('/', [InvoiceController::class, 'index'])->name('index');
        Route::get('/{tagihan}', [InvoiceController::class, 'show'])->name('show');

        Route::post('/kunjungan/{registrasi}', [InvoiceController::class, 'openForRegistration'])->name('buka');
        Route::post('/{tagihan}/segarkan', [InvoiceController::class, 'refresh'])->name('segarkan');
        Route::post('/{tagihan}/bayar', [InvoiceController::class, 'pay'])->name('bayar');
        Route::post('/{tagihan}/batal', [InvoiceController::class, 'voidInvoice'])->name('batal');

        Route::post('/pembayaran/{pembayaran}/batal', [InvoiceController::class, 'voidPayment'])->name('pembayaran.batal');

        // tambahan_biaya & potongan_biaya — dua kode Khanza, satu aksi,
        // dibedakan kolom kind pada billing.manual_adjustments.
        Route::post('/{tagihan}/penyesuaian', [InvoiceController::class, 'addAdjustment'])->name('penyesuaian.simpan');
        Route::post('/penyesuaian/{penyesuaian}/batal', [InvoiceController::class, 'voidAdjustment'])->name('penyesuaian.batal');
    });

    // piutang_pasien — piutang PASIEN (pulang belum lunas, dicicil),
    // beda dari piutang PENJAMIN di konteks finance (bayar_piutang) yang
    // ditagih lewat klaim. piutang_ralan/piutang_ranap tidak jadi gerbang
    // tersendiri, dibedakan care_type seperti pembayaran_ralan/ranap.
    Route::middleware('can:piutang_pasien')->prefix('piutang-pasien')->name('piutang-pasien.')->group(function () {
        Route::get('/', [ReceivableController::class, 'index'])->name('index');
        Route::post('/tagihan/{tagihan}', [ReceivableController::class, 'store'])->name('simpan');
        Route::post('/{piutang}/batal', [ReceivableController::class, 'cancel'])->name('batal');
    });
});
