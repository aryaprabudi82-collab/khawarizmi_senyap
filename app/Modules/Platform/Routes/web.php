<?php

use App\Modules\Platform\Http\Controllers\AuthController;
use App\Modules\Platform\Http\Controllers\RoleController;
use App\Modules\Platform\Http\Controllers\SettingController;
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

        /*
         * Pengaturan aplikasi & identitas rumah sakit (domain U item A).
         *
         * `aplikasi` menggerbangi layarnya dan menaungi `admin`, `set_nota`,
         * `set_no_rm`, `set_penggunaan_tarif`, `set_oto_ralan`,
         * `setup_jam_kamin`, `set_input_parsial`, `setup_embalase`, dan
         * `set_harga_obat` — kesepuluhnya di Khanza adalah menu terpisah untuk
         * satu baris pengaturan masing-masing, dan sepuluh layar berarti tidak
         * ada satu pun tempat yang bisa menjawab "apa saja yang belum diatur".
         *
         * Gerbangnya DIPISAH dari `user`: yang mengelola akun bukan
         * mesti orang yang boleh mengubah tarif embalase atau format nota.
         */
        Route::middleware('can:aplikasi')->prefix('pengaturan/aplikasi')
            ->name('platform.pengaturan.')->group(function () {
                Route::get('/', [SettingController::class, 'index'])->name('index');
                Route::post('/institusi', [SettingController::class, 'saveInstitution'])->name('institusi');
                Route::post('/{pengaturan}', [SettingController::class, 'update'])->name('perbarui');
            });
    });
});
