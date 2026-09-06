<?php

use App\Modules\Finance\Http\Controllers\AccountingController;
use App\Modules\Finance\Http\Controllers\CashController;
use App\Modules\Finance\Http\Controllers\PayableController;
use App\Modules\Finance\Http\Controllers\OtherReceivableController;
use App\Modules\Finance\Http\Controllers\LedgerController;
use App\Modules\Finance\Http\Controllers\ExpenseRequestController;
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

/*
| Domain K item B: hutang vendor. ~20 kode Khanza (titip_faktur_*,
| validasi_tagihan_*, hutang_*, ringkasan_hutang_vendor_*, bayar_pesan_*,
| tagihan_hutang_obat, akun_bayar_hutang) dilayani satu buku hutang untuk
| empat rantai pengadaan sekaligus.
|
| Menitipkan faktur digerbangi hutang_obat; MEMVALIDASI dan MEMBAYAR
| digerbangi terpisah lewat validasi_tagihan_hutang_obat — yang menitipkan
| faktur dan yang mengakui hutangnya sebaiknya bukan orang yang sama,
| prinsip pemisahan tugas yang sama seperti verifikasi penerimaan barang.
|
| bayar_pemesanan_obat SENGAJA TIDAK dipakai di sini meski namanya cocok:
| kode itu sudah dipegang apoteker untuk mencatat pembayaran penerimaan
| obat di konteks pharmacy, dan memakainya ulang akan diam-diam memberi
| apoteker kewenangan atas seluruh buku hutang rumah sakit lintas empat
| rantai pengadaan — perluasan wewenang yang tidak akan terlihat di mana
| pun kecuali di sini.
*/
Route::middleware(['web', 'auth', 'can:hutang_obat'])->prefix('hutang')->name('hutang.')->group(function () {
    Route::get('/', [PayableController::class, 'index'])->name('index');
    Route::post('/', [PayableController::class, 'store'])->name('simpan');
});

Route::middleware(['web', 'auth', 'can:validasi_tagihan_hutang_obat'])->prefix('hutang')->name('hutang.')->group(function () {
    Route::post('/{hutang}/validasi', [PayableController::class, 'validateInvoice'])->name('validasi');
    Route::post('/{hutang}/tolak', [PayableController::class, 'reject'])->name('tolak');
    Route::post('/{hutang}/bayar', [PayableController::class, 'pay'])->name('bayar');
});

/*
| Domain K item C: piutang non-pasien dan beban hutang lain. ~15 kode
| digerbangi piutang_jasa_perusahaan.
|
| Satu layar memuat dua arah yang berlawanan, tapi mekanismenya terpisah:
| piutang di finance.other_receivables, beban hutang lain di buku hutang
| yang sama dengan hutang vendor (source_context 'lain'). Khanza menaruh
| keduanya di satu menu; meleburnya jadi satu tabel akan membuat setiap
| laporan harus menyaring arah lebih dulu, dan cepat atau lambat ada yang
| lupa lalu menjumlahkan hutang bersama piutang.
*/
Route::middleware(['web', 'auth', 'can:piutang_jasa_perusahaan'])->prefix('piutang-lain')->name('piutang-lain.')->group(function () {
    Route::get('/', [OtherReceivableController::class, 'index'])->name('index');
    Route::post('/', [OtherReceivableController::class, 'store'])->name('simpan');
    Route::post('/kategori', [OtherReceivableController::class, 'storeCategory'])->name('kategori');
    Route::post('/{piutang}/bayar', [OtherReceivableController::class, 'collect'])->name('bayar');
    Route::post('/{piutang}/hapuskan', [OtherReceivableController::class, 'writeOff'])->name('hapuskan');
    Route::post('/hutang', [OtherReceivableController::class, 'storeOtherDebt'])->name('simpan-hutang');
    Route::post('/hutang/{hutang}/bayar', [OtherReceivableController::class, 'payOtherDebt'])->name('bayar-hutang');
});

/*
| Domain K item E: bagan akun, jurnal manual & buku besar. ~10 kode
| digerbangi akun_rekening.
|
| Layar inilah yang membuat seluruh pemetaan akun di item A/B/C bisa
| diisi sama sekali — sebelum ini hanya ada empat akun bawaan dari seeder
| dan tidak ada cara menambahnya, sehingga setiap peringatan "belum
| dipetakan ke bagan akun" tidak mungkin diselesaikan siapa pun.
|
| Posting jurnal digerbangi TERPISAH lewat posting_jurnal: membaca buku
| besar dan menulis ke dalamnya adalah dua kewenangan yang berbeda.
*/
Route::middleware(['web', 'auth', 'can:akun_rekening'])->prefix('buku')->name('buku.')->group(function () {
    Route::get('/', [LedgerController::class, 'index'])->name('index');
    Route::post('/akun', [LedgerController::class, 'storeAccount'])->name('akun.simpan');
    Route::post('/akun/{akun}/nonaktif', [LedgerController::class, 'deactivateAccount'])->name('akun.nonaktif');
    Route::post('/saldo-awal', [LedgerController::class, 'storeOpeningBalance'])->name('saldo-awal');
});

Route::middleware(['web', 'auth', 'can:posting_jurnal'])->prefix('buku')->name('buku.')->group(function () {
    Route::post('/jurnal', [LedgerController::class, 'postJournal'])->name('jurnal');
});

/*
| Domain K item F: pengajuan & persetujuan biaya. 4 kode, TIGA gerbang
| berbeda karena memang tiga orang berbeda.
|
| pengajuan_biaya                      -> mengajukan & melihat
| persetujuan_pengajuan_biaya          -> menyetujui / menolak
| validasi_persetujuan_pengajuan_biaya -> memvalidasi & mencairkan
|
| Pemisahannya ditegakkan DUA KALI: di gerbang peran dan di dalam service.
| Di rumah sakit kecil satu orang lazim memegang beberapa peran sekaligus,
| dan pemisahan yang cuma ada di gerbang akan runtuh persis di situ.
*/
Route::middleware(['web', 'auth', 'can:pengajuan_biaya'])->prefix('pengajuan-biaya')->name('pengajuan-biaya.')->group(function () {
    Route::get('/', [ExpenseRequestController::class, 'index'])->name('index');
    Route::post('/', [ExpenseRequestController::class, 'store'])->name('simpan');
});

Route::middleware(['web', 'auth', 'can:persetujuan_pengajuan_biaya'])->prefix('pengajuan-biaya')->name('pengajuan-biaya.')->group(function () {
    Route::post('/{pengajuan}/setuju', [ExpenseRequestController::class, 'approve'])->name('setuju');
    Route::post('/{pengajuan}/tolak', [ExpenseRequestController::class, 'reject'])->name('tolak');
});

Route::middleware(['web', 'auth', 'can:validasi_persetujuan_pengajuan_biaya'])->prefix('pengajuan-biaya')->name('pengajuan-biaya.')->group(function () {
    Route::post('/{pengajuan}/validasi', [ExpenseRequestController::class, 'validateApproval'])->name('validasi');
    Route::post('/{pengajuan}/cairkan', [ExpenseRequestController::class, 'disburse'])->name('cairkan');
});
