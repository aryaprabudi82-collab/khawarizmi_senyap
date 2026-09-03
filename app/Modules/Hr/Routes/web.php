<?php

use App\Modules\Hr\Http\Controllers\AttendanceController;
use App\Modules\Hr\Http\Controllers\EmployeeController;
use App\Modules\Hr\Http\Controllers\EmployeeHistoryController;
use App\Modules\Hr\Http\Controllers\LeaveController;
use App\Modules\Hr\Http\Controllers\PerformanceAppraisalController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])
    ->prefix('kepegawaian')
    ->name('hr.')
    ->group(function () {

        Route::middleware('can:pegawai_user')->group(function () {
            Route::get('/', [EmployeeController::class, 'index'])->name('index');
            Route::post('/pegawai', [EmployeeController::class, 'store'])->name('pegawai.simpan');
            Route::post('/pegawai/{pegawai}', [EmployeeController::class, 'update'])->name('pegawai.perbarui');
            Route::post('/jenis-cuti', [EmployeeController::class, 'storeLeaveType'])->name('jenis-cuti.simpan');
            Route::post('/jenis-cuti/{jenisCuti}', [EmployeeController::class, 'updateLeaveType'])->name('jenis-cuti.perbarui');

            // Riwayat pegawai (jabatan, gaji, pendidikan, catatan) — lihat
            // catatan migrasi hr untuk alasan digerbangi pegawai_user, bukan
            // permission tersendiri per riwayat.
            Route::prefix('pegawai/{pegawai}')->name('pegawai.')->group(function () {
                Route::get('/', [EmployeeHistoryController::class, 'show'])->name('detail');
                Route::post('/jabatan', [EmployeeHistoryController::class, 'storePosition'])->name('jabatan.simpan');
                Route::post('/gaji', [EmployeeHistoryController::class, 'storeSalary'])->name('gaji.simpan');
                Route::post('/pendidikan', [EmployeeHistoryController::class, 'storeEducation'])->name('pendidikan.simpan');
                Route::post('/pendidikan/{pendidikan}', [EmployeeHistoryController::class, 'updateEducation'])->name('pendidikan.perbarui');
                Route::post('/catatan', [EmployeeHistoryController::class, 'storeRecord'])->name('catatan.simpan');
                Route::post('/catatan/{catatan}', [EmployeeHistoryController::class, 'updateRecord'])->name('catatan.perbarui');
            });
        });

        Route::middleware('can:pengajuan_cuti')->prefix('cuti')->name('cuti.')->group(function () {
            Route::get('/', [LeaveController::class, 'index'])->name('index');
            Route::post('/', [LeaveController::class, 'store'])->name('simpan');
            Route::post('/{pengajuan}/setuju', [LeaveController::class, 'approve'])->name('setuju');
            Route::post('/{pengajuan}/tolak', [LeaveController::class, 'reject'])->name('tolak');
            Route::post('/{pengajuan}/batal', [LeaveController::class, 'cancel'])->name('batal');
        });

        Route::middleware('can:presensi_harian')->prefix('presensi')->name('presensi.')->group(function () {
            Route::get('/', [AttendanceController::class, 'index'])->name('index');
            Route::get('/bulanan', [AttendanceController::class, 'monthly'])->name('bulanan');
            Route::post('/{pegawai}/masuk', [AttendanceController::class, 'checkIn'])->name('masuk');
            Route::post('/{pegawai}/pulang', [AttendanceController::class, 'checkOut'])->name('pulang');
            Route::post('/manual', [AttendanceController::class, 'storeManual'])->name('manual');
        });

        Route::middleware('can:skp_penilaian')->prefix('skp')->name('skp.')->group(function () {
            Route::get('/', [PerformanceAppraisalController::class, 'index'])->name('index');
            Route::post('/', [PerformanceAppraisalController::class, 'store'])->name('simpan');
            Route::post('/{penilaian}/finalisasi', [PerformanceAppraisalController::class, 'finalize'])->name('finalisasi');
        });

    });
