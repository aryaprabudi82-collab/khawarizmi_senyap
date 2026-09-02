<?php

use App\Modules\Billing\Http\Controllers\InvoiceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'can:pembayaran_ralan'])->group(function () {
    Route::prefix('tagihan')->name('tagihan.')->group(function () {
        Route::get('/', [InvoiceController::class, 'index'])->name('index');
        Route::get('/{tagihan}', [InvoiceController::class, 'show'])->name('show');

        Route::post('/kunjungan/{registrasi}', [InvoiceController::class, 'openForRegistration'])->name('buka');
        Route::post('/{tagihan}/segarkan', [InvoiceController::class, 'refresh'])->name('segarkan');
        Route::post('/{tagihan}/bayar', [InvoiceController::class, 'pay'])->name('bayar');
        Route::post('/{tagihan}/batal', [InvoiceController::class, 'voidInvoice'])->name('batal');

        Route::post('/pembayaran/{pembayaran}/batal', [InvoiceController::class, 'voidPayment'])->name('pembayaran.batal');
    });
});
