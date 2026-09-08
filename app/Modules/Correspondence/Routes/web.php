<?php

use App\Modules\Correspondence\Http\Controllers\AnnouncementController;
use App\Modules\Correspondence\Http\Controllers\CertificateController;
use App\Modules\Correspondence\Http\Controllers\ConsentController;
use App\Modules\Correspondence\Http\Controllers\ConsentTemplateController;
use App\Modules\Correspondence\Http\Controllers\LetterController;
use App\Modules\Correspondence\Http\Controllers\PatientRequestController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])
    ->prefix('surat')
    ->name('correspondence.')
    ->group(function () {

        Route::middleware('can:surat_masuk')->group(function () {
            Route::get('/', [LetterController::class, 'index'])->name('index');
            Route::post('/masuk', [LetterController::class, 'storeIncoming'])->name('masuk.simpan');
            Route::post('/masuk/{surat}/disposisi', [LetterController::class, 'disposition'])->name('masuk.disposisi');
            Route::post('/masuk/{surat}/arsip', [LetterController::class, 'archiveIncoming'])->name('masuk.arsip');
            Route::post('/keluar', [LetterController::class, 'storeOutgoing'])->name('keluar.simpan');
            Route::post('/keluar/{surat}/kirim', [LetterController::class, 'send'])->name('keluar.kirim');
        });

        Route::middleware('can:pengumuman_epasien')->prefix('pengumuman')->name('pengumuman.')->group(function () {
            Route::get('/', [AnnouncementController::class, 'index'])->name('index');
            Route::post('/', [AnnouncementController::class, 'store'])->name('simpan');
            Route::post('/{pengumuman}', [AnnouncementController::class, 'update'])->name('perbarui');
        });

        Route::middleware('can:persetujuan_penolakan_tindakan')->prefix('persetujuan')->name('persetujuan.')->group(function () {
            Route::get('/', [ConsentController::class, 'index'])->name('index');
            Route::post('/', [ConsentController::class, 'store'])->name('simpan');
            Route::post('/template', [ConsentController::class, 'storeFromTemplate'])->name('dari-template');
            Route::post('/butir/{butir}', [ConsentController::class, 'confirmItem'])->name('butir.konfirmasi');
            Route::post('/{persetujuan}/putuskan', [ConsentController::class, 'decide'])->name('putuskan');
            Route::post('/{persetujuan}/batal', [ConsentController::class, 'cancel'])->name('batal');
            Route::get('/{persetujuan}/cetak', [ConsentController::class, 'print'])->name('cetak');
        });

        /*
         * Master persetujuan: template penjelasan + daftar alasan penolakan.
         * Satu layar, dua kode Khanza — master_menolak_anjuran_medis ikut
         * digerbangi kode template sebagai umbrella.
         */
        Route::middleware('can:template_persetujuan_penolakan_tindakan')
            ->prefix('persetujuan/master')->name('persetujuan.master.')->group(function () {
                Route::get('/', [ConsentTemplateController::class, 'index'])->name('index');
                Route::post('/template', [ConsentTemplateController::class, 'store'])->name('template.simpan');
                Route::post('/alasan', [ConsentTemplateController::class, 'storeReason'])->name('alasan.simpan');
            });

        /*
         * Hak pasien: lima jenis permintaan (privasi, perlindungan dari
         * kekerasan, bimbingan rohani, second opinion, cuti perawatan) dan
         * serah terima barang/anggota tubuh — enam kode Khanza, satu layar,
         * digerbangi surat_permohonan_privasi sebagai umbrella.
         *
         * Satu gerbang, bukan enam: keenamnya dikerjakan petugas ruangan yang
         * sama, dan memecahnya akan melahirkan kewenangan yang tidak
         * dipegang siapa pun.
         */
        Route::middleware('can:surat_permohonan_privasi')
            ->prefix('hak-pasien')->name('hak-pasien.')->group(function () {
                Route::get('/', [PatientRequestController::class, 'index'])->name('index');
                Route::post('/', [PatientRequestController::class, 'store'])->name('simpan');
                Route::post('/{permintaan}/jawab', [PatientRequestController::class, 'respond'])->name('jawab');
                Route::post('/serah-terima', [PatientRequestController::class, 'storeHandover'])->name('serah-terima');
            });

        Route::middleware('can:surat_keterangan_sehat')->prefix('keterangan')->name('keterangan.')->group(function () {
            Route::get('/', [CertificateController::class, 'index'])->name('index');
            Route::post('/', [CertificateController::class, 'store'])->name('simpan');
            Route::post('/{surat}/batal', [CertificateController::class, 'cancel'])->name('batal');
            Route::get('/{surat}/cetak', [CertificateController::class, 'print'])->name('cetak');
        });

    });
