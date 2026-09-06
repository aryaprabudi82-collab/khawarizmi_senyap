<?php

use App\Modules\Finance\Http\Controllers\AccountingController;
use App\Modules\Finance\Http\Controllers\CashController;
use App\Modules\Finance\Http\Controllers\CostEstimateController;
use App\Modules\Finance\Http\Controllers\DepositController;
use App\Modules\Finance\Http\Controllers\ReceivableController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'can:bayar_piutang'])->group(function () {
    Route::prefix('piutang')->name('piutang.')->group(function () {
        Route::get('/', [ReceivableController::class, 'index'])->name('index');
        Route::post('/{piutang}/tagih', [ReceivableController::class, 'collect'])->name('tagih');
    });
});

// deposit_pasien dan perkiraan_biaya_ranap — tercatat context=encounter di
// katalog (domain A Khanza), sungguhan di finance (paket Java "keuangan"),
// lihat catatan migrasi 2026_09_17_000001_create_deposits_and_cost_estimates.
Route::middleware(['web', 'auth', 'can:deposit_pasien'])->group(function () {
    Route::prefix('deposit')->name('deposit.')->group(function () {
        Route::get('/', [DepositController::class, 'index'])->name('index');
        Route::post('/', [DepositController::class, 'store'])->name('simpan');
    });
});

Route::middleware(['web', 'auth', 'can:perkiraan_biaya_ranap'])->group(function () {
    Route::prefix('estimasi-ranap')->name('estimasi-ranap.')->group(function () {
        Route::get('/', [CostEstimateController::class, 'index'])->name('index');
        Route::post('/', [CostEstimateController::class, 'store'])->name('simpan');
        Route::get('/{estimasi}/cetak', [CostEstimateController::class, 'print'])->name('cetak');
    });
});

/*
| Domain I item E: akuntansi. Tujuh kodenya ditandai katalog context=billing,
| tapi dibangun di sini (dikonfirmasi user) karena isinya memetakan uang ke
| bagan akun — dan chart_of_accounts serta jurnal memang tinggal di finance.
|
| Penutupan periode digerbangi terpisah: menutup buku konsekuensinya berbeda
| dari sekadar melihat laporannya.
*/
Route::middleware(['web', 'auth', 'can:pendapatan_per_akun'])->prefix('akuntansi')->name('akuntansi.')->group(function () {
    Route::get('/', [AccountingController::class, 'index'])->name('index');
    Route::post('/pemetaan', [AccountingController::class, 'storeMapping'])->name('pemetaan');
});

Route::middleware(['web', 'auth', 'can:pendapatan_per_akun_closing'])->prefix('akuntansi')->name('akuntansi.')->group(function () {
    Route::post('/tutup', [AccountingController::class, 'close'])->name('tutup');
    Route::post('/penutupan/{penutupan}/buka', [AccountingController::class, 'reopen'])->name('buka');
});

/*
| Domain K item A: kas harian. Delapan kode Khanza (pemasukan_lain,
| kategori_pemasukan_lain, pengeluaran, kategori_pengeluaran_harian,
| pengeluaran_pengeluaran, omset_penerimaan, cashflow, keuangan) dilayani
| satu layar, digerbangi pengeluaran — kode paling representatif karena
| pengeluaran harian yang paling sering disentuh petugas keuangan.
|
| Pemasukan dan pengeluaran sengaja satu mekanisme, dibedakan arah pada
| kategorinya; lihat catatan migrasi untuk alasannya.
*/
Route::middleware(['web', 'auth', 'can:pengeluaran'])->prefix('kas')->name('kas.')->group(function () {
    Route::get('/', [CashController::class, 'index'])->name('index');
    Route::post('/', [CashController::class, 'store'])->name('simpan');
    Route::post('/kategori', [CashController::class, 'storeCategory'])->name('kategori');
    Route::post('/{transaksi}/batal', [CashController::class, 'cancel'])->name('batal');
});
