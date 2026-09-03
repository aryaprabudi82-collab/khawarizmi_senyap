<?php

use App\Modules\Platform\Http\Controllers\AuthController;
use App\Modules\Platform\Http\Controllers\RoleController;
use App\Modules\Platform\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('/masuk', [AuthController::class, 'form'])->name('masuk');
        Route::post('/masuk', [AuthController::class, 'login'])->name('masuk.kirim');
    });

    Route::middleware('auth')->group(function () {
        Route::post('/keluar', [AuthController::class, 'logout'])->name('keluar');

        // Gerbang tunggal 'user' — kode domain U Khanza ("Set User") yang selama
        // ini tidak punya layar. Menggenapi PermissionRegistry supaya domain U
        // tidak lagi menampilkan hak "granted" tanpa fitur sungguhan di baliknya.
        Route::middleware('can:user')->prefix('pengaturan')->name('platform.')->group(function () {
            Route::prefix('pengguna')->name('pengguna.')->group(function () {
                Route::get('/', [UserController::class, 'index'])->name('index');
                Route::post('/', [UserController::class, 'store'])->name('simpan');
                Route::post('/{pengguna}', [UserController::class, 'update'])->name('perbarui');
                Route::post('/{pengguna}/reset-sandi', [UserController::class, 'resetPassword'])->name('reset-sandi');
                Route::post('/{pengguna}/{status}', [UserController::class, 'setActive'])
                    ->whereIn('status', ['aktifkan', 'nonaktifkan'])
                    ->name('status');
            });

            Route::prefix('peran')->name('peran.')->group(function () {
                Route::get('/', [RoleController::class, 'index'])->name('index');
                Route::post('/', [RoleController::class, 'store'])->name('simpan');
                Route::get('/{peran}', [RoleController::class, 'show'])->name('show');
                Route::post('/{peran}', [RoleController::class, 'update'])->name('perbarui');
                Route::delete('/{peran}', [RoleController::class, 'destroy'])->name('hapus');
            });
        });
    });
});
