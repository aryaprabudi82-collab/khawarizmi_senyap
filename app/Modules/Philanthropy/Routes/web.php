<?php

use App\Modules\Philanthropy\Http\Controllers\AidController;
use App\Modules\Philanthropy\Http\Controllers\CriteriaController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])
    ->prefix('zis')
    ->name('philanthropy.')
    ->group(function () {

        /*
         * Kriteria asesmen — keenam belas kosakata domain T dalam satu layar
         * berpenyaring kategori. `zis_kategori_asnaf_penerima_dankes` dipilih
         * jadi gerbangnya dengan sengaja: ia satu-satunya kode ber-kind
         * Master yang isinya ditetapkan DI LUAR rumah sakit (At-Taubah 60),
         * dan karena itu satu-satunya yang boleh diseed. Ia menaungi lima
         * belas kode kosakata lainnya — memecahnya jadi enam belas gerbang
         * berarti enam belas kewenangan untuk enam belas daftar berbentuk
         * sama persis, dan tidak satu pun membuka pekerjaan sesungguhnya.
         */
        Route::middleware('can:zis_kategori_asnaf_penerima_dankes')
            ->prefix('kriteria')->name('kriteria.')->group(function () {
                Route::get('/', [CriteriaController::class, 'index'])->name('index');
                Route::post('/', [CriteriaController::class, 'store'])->name('simpan');
                Route::post('/{kriteria}/aktif', [CriteriaController::class, 'toggle'])->name('aktif');
            });

        /*
         * Layar kerja amil. Gerbangnya DIPISAH dari kriteria dengan sengaja:
         * yang menyusun instrumen dan yang memutuskan siapa layak menerima
         * uang tidak harus orang yang sama, dan menggabungkannya membuat
         * satu orang bisa mengubah kriterianya lalu memutuskan dengan
         * kriteria yang baru saja ia ubah.
         *
         * `zis_pengeluaran_penerima_dankes` yang dipakai — kode pertama
         * domain T dan ber-kind Transaksi di katalog Khanza, meski tabelnya
         * sendiri kosakata belaka.
         */
        Route::middleware('can:zis_pengeluaran_penerima_dankes')
            ->prefix('bantuan')->name('bantuan.')->group(function () {
                Route::get('/', [AidController::class, 'index'])->name('index');
                Route::post('/penerima', [AidController::class, 'storeRecipient'])->name('penerima.simpan');
                Route::post('/penerima/{penerima}/asnaf', [AidController::class, 'setAsnaf'])->name('penerima.asnaf');
                Route::post('/penerima/{penerima}/asesmen', [AidController::class, 'storeAssessment'])->name('asesmen.buka');
                Route::get('/asesmen/{asesmen}', [AidController::class, 'showAssessment'])->name('asesmen');
                Route::post('/asesmen/{asesmen}/jawab', [AidController::class, 'answer'])->name('asesmen.jawab');
                Route::post('/asesmen/{asesmen}/putuskan', [AidController::class, 'decide'])->name('asesmen.putuskan');
                Route::post('/asesmen/{asesmen}/salur', [AidController::class, 'disburse'])->name('asesmen.salur');
            });

    });
