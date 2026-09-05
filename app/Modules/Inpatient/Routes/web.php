<?php

use App\Modules\Inpatient\Http\Controllers\AdmissionController;
use App\Modules\Inpatient\Http\Controllers\RoomController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])
    ->prefix('rawat-inap')
    ->name('inpatient.')
    ->group(function () {

        // Dashboardnya sendiri boleh dilihat siapa pun yang punya tindakan_ranap
        // ATAU diet_pasien — gerbangnya diperiksa imperatif di controller (pola
        // sama dengan Order::assertAccess()) karena middleware can: tidak
        // mendukung OR. Tombol per aksi (admisi/pulangkan/kelola kamar) tetap
        // digerbangi @can('tindakan_ranap') di view, jadi pemegang diet_pasien
        // saja (mis. dokter lewat wholesale context encounter) cuma melihat
        // kolom diet, bukan tombol kelola kamar/bed.
        Route::get('/', [AdmissionController::class, 'index'])->name('index');

        // Gerbang tunggal tindakan_ranap untuk seluruh aksi kelola kamar/admisi —
        // sama pola dengan pegawai_user di hr, lihat catatan migrasi inpatient.
        Route::middleware('can:tindakan_ranap')->group(function () {
            Route::post('/admisi', [AdmissionController::class, 'store'])->name('admisi.simpan');
            Route::post('/admisi/{admisi}/pulang', [AdmissionController::class, 'discharge'])->name('admisi.pulang');
            // dpjp_ranap — ganti DPJP di tengah rawatan, digerbangi bareng
            // aksi admisi/pulangkan lainnya (keputusan alih rawat datang dari
            // dokter, tapi dicatat lewat layar bangsal yang sama).
            Route::post('/admisi/{admisi}/dpjp', [AdmissionController::class, 'reassignDpjp'])->name('admisi.dpjp.simpan');

            // Pindah bed mencatat riwayat penempatan, bukan menimpa bed_id —
            // itu yang menjaga biaya kamar per hari tetap benar saat kelas berubah.
            Route::post('/admisi/{admisi}/pindah-bed', [AdmissionController::class, 'transferBed'])->name('admisi.pindah-bed');

            Route::prefix('kamar')->name('kamar.')->group(function () {
                Route::get('/', [RoomController::class, 'index'])->name('index');
                Route::post('/', [RoomController::class, 'store'])->name('simpan');
                Route::post('/{kamar}', [RoomController::class, 'update'])->name('perbarui');
                Route::post('/{kamar}/bed', [RoomController::class, 'addBed'])->name('bed.simpan');
                Route::post('/bed/{bed}/bersih', [RoomController::class, 'markClean'])->name('bed.bersih');
                Route::post('/bed/{bed}/nonaktifkan', [RoomController::class, 'deactivateBed'])->name('bed.nonaktifkan');
                Route::post('/bed/{bed}/aktifkan', [RoomController::class, 'reactivateBed'])->name('bed.aktifkan');
            });
        });

        // diet_pasien digerbangi terpisah dari tindakan_ranap — kelola kamar/bed
        // itu kerja bangsal/administratif, order diet itu keputusan yang
        // (kelak) mungkin dipegang peran lain (mis. ahli gizi), bukan otomatis
        // ikut siapa pun yang bisa mengelola kamar.
        Route::middleware('can:diet_pasien')->prefix('admisi/{admisi}/diet')->name('admisi.diet.')->group(function () {
            Route::post('/', [AdmissionController::class, 'storeDiet'])->name('simpan');
            Route::post('/{diet}/hentikan', [AdmissionController::class, 'stopDiet'])->name('hentikan');
        });

    });
