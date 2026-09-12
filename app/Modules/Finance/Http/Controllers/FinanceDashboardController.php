<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Services\AccountingReportService;
use App\Modules\Finance\Services\CashService;
use App\Modules\Finance\Services\FinancialStatementService;
use App\Modules\Finance\Services\LedgerService;
use App\Modules\Finance\Services\OtherReceivableService;
use App\Modules\Finance\Services\PayableService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Pusat Keuangan — satu layar untuk seluruh konteks finance.
 *
 * MENGAPA DIBUAT. Konteks ini tumbuh jadi sembilan layar terpisah — kas,
 * buku besar, akuntansi, hutang, piutang pasien, piutang & hutang lain,
 * deposit, perkiraan biaya, pengajuan biaya — masing-masing benar
 * sendiri-sendiri, tapi tidak ada satu pun tempat yang menjawab
 * pertanyaan paling sering ditanyakan pimpinan: bagaimana keadaan
 * keuangan rumah sakit hari ini. Membukanya menuntut sembilan alamat
 * dihafal dan sembilan angka dijumlahkan di kepala.
 *
 * LAYAR INI TIDAK MENGHITUNG APA PUN SENDIRI. Seluruh angkanya diminta
 * dari layanan yang sudah ada dan sudah diuji. Menghitung ulang di sini
 * akan melahirkan angka kedua yang bisa berbeda dari layar aslinya — dan
 * dua angka berbeda untuk hal yang sama jauh lebih buruk daripada satu
 * angka yang harus dicari di layar lain.
 *
 * DAN IA TIDAK MENGGANTIKAN KESEMBILANNYA. Yang di sini ringkasan dan
 * laporan pokok; pencatatan tetap di layar masing-masing, karena
 * menumpuk seluruh formulir di satu halaman membuat tidak ada satu pun
 * yang bisa digerbangi terpisah — dan kewenangan mencatat kas memang
 * berbeda dari kewenangan menutup periode.
 */
class FinanceDashboardController
{
    public function __construct(
        private readonly FinancialStatementService $laporan,
        private readonly LedgerService $buku,
        private readonly CashService $kas,
        private readonly PayableService $hutang,
        private readonly OtherReceivableService $piutangLain,
        private readonly AccountingReportService $akuntansi,
    ) {}

    public function index(Request $request): View
    {
        $dari = $request->query('dari', now()->startOfMonth()->toDateString());
        $sampai = $request->query('sampai', now()->toDateString());

        $neraca = $this->laporan->neraca($sampai);
        $labaRugi = $this->laporan->labaRugi($dari, $sampai);

        return view('finance::pusat.index', [
            'dari' => $dari,
            'sampai' => $sampai,

            // Laporan pokok.
            'neraca' => $neraca,
            'labaRugi' => $labaRugi,
            'neracaSaldo' => $this->buku->trialBalance($dari, $sampai),

            // Ringkasan operasional, diambil dari layar masing-masing.
            'kas' => $this->kas->summary($dari, $sampai),
            'hutang' => $this->hutang->aging(),
            'piutangLain' => $this->piutangLain->aging(),

            // Apa yang menghalangi laporan ini dipakai menutup buku.
            'kesiapan' => $this->kesiapan($dari, $sampai),
        ]);
    }

    /**
     * Syarat yang belum terpenuhi, disebut terang di kepala layar.
     *
     * MENGAPA INI ADA DI ATAS, BUKAN DI CATATAN KAKI. Laporan keuangan
     * yang tersaji rapi akan dipercaya apa adanya — dan selama bagan akun
     * masih berisi contoh seeder serta sebagian uang belum terpetakan ke
     * akun mana pun, angkanya BELUM bisa dipakai menutup buku. Neraca
     * yang tampak seimbang padahal separuh transaksinya tidak pernah
     * masuk jurnal adalah bentuk kesalahan yang paling sulit ketahuan:
     * tidak ada yang terlihat salah.
     *
     * @return list<array{judul: string, siap: bool, akibat: string}>
     */
    private function kesiapan(string $dari, string $sampai): array
    {
        $akun = Account::query()->where('is_active', true)->count();
        $belumDipetakanKas = $this->kas->unmappedTotal($dari, $sampai);
        $belumDipetakanHutang = $this->hutang->unmappedTotal();
        $belumDipetakanPiutang = $this->piutangLain->unmappedTotal();
        $uangBelumBerakun = $this->akuntansi->unmappedTotal($dari, $sampai);

        $rupiah = fn (float $n) => 'Rp '.number_format($n, 0, ',', '.');

        return [
            [
                'judul' => 'Bagan akun RSP UI',
                'siap' => $akun > 10,
                'akibat' => $akun > 10
                    ? $akun.' akun aktif terdaftar.'
                    : 'Baru '.$akun.' akun terdaftar — itu contoh pengembangan, bukan bagan akun '
                        .'RSP UI. Selama bagan akunnya belum disusun, neraca dan laba-rugi di bawah '
                        .'hanya memuat sebagian kecil transaksi, dan sisanya tidak muncul di mana pun. '
                        .'Disusun bagian keuangan RSP UI lewat layar Buku Besar.',
            ],
            [
                'judul' => 'Pendapatan terpetakan ke akun',
                'siap' => $uangBelumBerakun === 0.0,
                'akibat' => $uangBelumBerakun === 0.0
                    ? 'Seluruh pembayaran pada rentang ini sudah punya akun.'
                    : $rupiah($uangBelumBerakun).' diterima tapi cara bayarnya belum dipetakan ke '
                        .'akun mana pun, jadi uang itu TIDAK MASUK laba-rugi di bawah. Dipetakan '
                        .'lewat layar Akuntansi.',
            ],
            [
                'judul' => 'Kas, hutang & piutang terpetakan',
                'siap' => $belumDipetakanKas === 0.0
                    && $belumDipetakanHutang === 0.0
                    && $belumDipetakanPiutang === 0.0,
                'akibat' => ($belumDipetakanKas === 0.0 && $belumDipetakanHutang === 0.0 && $belumDipetakanPiutang === 0.0)
                    ? 'Seluruh kategori sudah punya akun.'
                    : 'Belum berakun — kas '.$rupiah($belumDipetakanKas)
                        .', hutang '.$rupiah($belumDipetakanHutang)
                        .', piutang lain '.$rupiah($belumDipetakanPiutang)
                        .'. Nilainya tercatat di layarnya masing-masing tapi belum masuk buku besar.',
            ],
        ];
    }
}
