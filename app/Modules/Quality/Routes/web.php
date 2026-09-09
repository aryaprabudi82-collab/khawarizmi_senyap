<?php

use App\Modules\Quality\Http\Controllers\HaisController;
use App\Modules\Quality\Http\Controllers\IcraController;
use App\Modules\Quality\Http\Controllers\IncidentController;
use App\Modules\Quality\Http\Controllers\K3IncidentController;
use App\Modules\Quality\Http\Controllers\K3RecapController;
use App\Modules\Quality\Http\Controllers\PpiAuditController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])
    ->prefix('mutu')
    ->name('quality.')
    ->group(function () {

        Route::middleware('can:insiden_keselamatan_pasien')->prefix('insiden')->name('insiden.')->group(function () {
            Route::get('/', [IncidentController::class, 'index'])->name('index');
            Route::post('/', [IncidentController::class, 'store'])->name('simpan');
            Route::post('/{insiden}/tinjau', [IncidentController::class, 'review'])->name('tinjau');
            Route::post('/{insiden}/tutup', [IncidentController::class, 'close'])->name('tutup');
        });

        Route::middleware('can:pcra_icra_pengkajian_risiko_prakonstruksi')->prefix('icra')->name('icra.')->group(function () {
            Route::get('/', [IcraController::class, 'index'])->name('index');
            Route::post('/', [IcraController::class, 'store'])->name('simpan');
            Route::post('/{kajian}/selesai', [IcraController::class, 'complete'])->name('selesai');
            Route::post('/{kajian}/batal', [IcraController::class, 'cancel'])->name('batal');

            /*
             * Master ICRA: area & kelompok risikonya, matriks, tindakan
             * pengendalian, dan persyaratan per kelas. Lima kode Khanza di
             * satu layar, digerbangi kode pengkajian sebagai umbrella —
             * yang menyusun kosakatanya adalah IPCN yang sama dengan yang
             * mengisi pengkajiannya, dan memecah gerbangnya melahirkan
             * kewenangan yang tidak dipegang siapa pun.
             */
            Route::prefix('master')->name('master.')->group(function () {
                Route::get('/', [IcraController::class, 'master'])->name('index');
                Route::post('/area', [IcraController::class, 'storeArea'])->name('area.simpan');
                Route::post('/tindakan', [IcraController::class, 'storeControlMeasure'])->name('tindakan.simpan');
                Route::post('/persyaratan', [IcraController::class, 'storeRequirement'])->name('persyaratan.simpan');
                Route::post('/matriks/{sel}', [IcraController::class, 'updateMatrix'])->name('matriks.perbarui');
            });
        });

        Route::middleware('can:audit_kepatuhan_apd')->prefix('ppi')->name('ppi.')->group(function () {
            Route::get('/', [PpiAuditController::class, 'index'])->name('index');
            Route::post('/', [PpiAuditController::class, 'store'])->name('simpan');
        });

        Route::middleware('can:peristiwa_k3rs')->prefix('k3')->name('k3.')->group(function () {
            Route::get('/', [K3IncidentController::class, 'index'])->name('index');
            Route::post('/', [K3IncidentController::class, 'store'])->name('simpan');
            Route::post('/{insiden}/tinjau', [K3IncidentController::class, 'review'])->name('tinjau');
            Route::post('/{insiden}/tutup', [K3IncidentController::class, 'close'])->name('tutup');
        });

        Route::middleware('can:jenis_cidera_k3rstahun')->get('/k3/rekap', [K3RecapController::class, 'index'])->name('k3.rekap');

        /*
        | Domain J item E: surveilans HAIs. Empat kode laporan Khanza
        | (harian_HAIs, harian_HAIs2, bulanan_HAIs, hais_perbangsal) tidak
        | bisa berdiri tanpa pencatatan kejadian infeksinya, yang belum
        | pernah ada — ppi_audits baru memegang audit kepatuhan bundle.
        | Pencatatan dan laporan disatukan karena keduanya pekerjaan tim
        | PPI yang sama, dan catatan penyebut yang bolong paling berguna
        | terlihat tepat di sebelah tempat mengisinya.
        */
        Route::middleware('can:harian_HAIs')->prefix('hais')->name('hais.')->group(function () {
            Route::get('/', [HaisController::class, 'index'])->name('index');
            Route::post('/', [HaisController::class, 'store'])->name('simpan');
            Route::post('/penyebut', [HaisController::class, 'storeDenominator'])->name('penyebut');
        });

    });
