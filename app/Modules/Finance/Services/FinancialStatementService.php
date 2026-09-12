<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\Account;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Neraca & Laba-Rugi — dua laporan keuangan pokok.
 *
 * DIBANGUN SETELAH VERIFIKASI MENEMUKAN KEDUANYA TIDAK ADA. Yang selama
 * ini tersedia baru NERACA SALDO (trial balance): daftar saldo debit dan
 * kredit per akun. Itu bahan bakunya, bukan laporannya — neraca saldo
 * menjawab "apakah pembukuannya seimbang", bukan "berapa harta rumah
 * sakit" maupun "apakah tahun ini surplus".
 *
 * DUA LAPORAN INI MENJAWAB PERTANYAAN YANG BERBEDA SIFATNYA, dan itu
 * menentukan cara menghitungnya:
 *
 *   NERACA adalah KEADAAN pada satu tanggal. Saldonya dihitung dari
 *   SELURUH jurnal sejak awal sampai tanggal itu, ditambah saldo awal
 *   tahun. Menghitungnya dari satu rentang akan menghasilkan "aset yang
 *   bertambah bulan ini", bukan aset yang dimiliki — angka yang masuk
 *   akal, jauh lebih kecil, dan salah.
 *
 *   LABA-RUGI adalah ALIRAN sepanjang satu periode. Saldonya dihitung
 *   HANYA dari jurnal di dalam rentang itu. Memasukkan periode sebelumnya
 *   akan menjumlahkan pendapatan tahun-tahun lalu ke tahun ini.
 *
 * SURPLUS BERJALAN MASUK KE NERACA SEBAGAI BAGIAN MODAL. Tanpa itu neraca
 * tidak akan pernah seimbang selama ada pendapatan atau beban yang belum
 * ditutup ke akun modal — dan neraca yang tidak seimbang akan dibaca
 * sebagai kerusakan sistem, padahal yang kurang cuma satu baris.
 *
 * ISTILAH "SURPLUS/DEFISIT", BUKAN "LABA/RUGI", pada penyajiannya:
 * RSP UI rumah sakit pendidikan, bukan badan usaha yang mengejar laba,
 * dan menamai selisihnya "laba" mengubah cara orang membaca angkanya.
 * Nama methodnya tetap memakai istilah akuntansi yang lazim supaya yang
 * membaca kodenya tidak perlu menerjemahkan dua kali.
 */
class FinancialStatementService
{
    /**
     * NERACA per satu tanggal.
     *
     * @return object{
     *     tanggal: string,
     *     kelompok: Collection<string, Collection<int, object>>,
     *     total: array<string, float>,
     *     surplus_berjalan: float,
     *     total_aset: float,
     *     total_kewajiban_modal: float,
     *     selisih: float,
     *     seimbang: bool,
     *     belum_dijurnal: bool
     * }
     */
    public function neraca(string $tanggal): object
    {
        $baris = $this->saldoAkun(null, $tanggal)
            ->filter(fn (object $b) => Account::golonganDari($b->type) !== Account::GOL_PENDAPATAN
                && Account::golonganDari($b->type) !== Account::GOL_BEBAN);

        $kelompok = $baris->groupBy(fn (object $b) => Account::golonganDari($b->type));

        $total = [];

        foreach ([Account::GOL_ASET, Account::GOL_KEWAJIBAN, Account::GOL_MODAL] as $g) {
            $total[$g] = round((float) ($kelompok[$g] ?? collect())->sum('saldo'), 2);
        }

        /*
         * Surplus berjalan dihitung dari SELURUH pendapatan dan beban
         * sampai tanggal neraca — bukan dari satu periode. Neraca
         * menyajikan keadaan kumulatif, dan laba ditahan pun kumulatif.
         */
        $surplus = $this->surplusSampai($tanggal);

        $aset = $total[Account::GOL_ASET];
        $kewajibanModal = round($total[Account::GOL_KEWAJIBAN] + $total[Account::GOL_MODAL] + $surplus, 2);

        return (object) [
            'tanggal' => $tanggal,
            'kelompok' => $kelompok,
            'total' => $total,
            'surplus_berjalan' => $surplus,
            'total_aset' => $aset,
            'total_kewajiban_modal' => $kewajibanModal,
            'selisih' => round($aset - $kewajibanModal, 2),
            'seimbang' => round($aset - $kewajibanModal, 2) === 0.0,
            'belum_dijurnal' => $baris->isEmpty() && $surplus === 0.0,
        ];
    }

    /**
     * LABA-RUGI (surplus/defisit) sepanjang satu periode.
     *
     * @return object{
     *     dari: string, sampai: string,
     *     pendapatan: Collection<int, object>, beban: Collection<int, object>,
     *     total_pendapatan: float, total_beban: float,
     *     surplus: float, belum_dijurnal: bool
     * }
     */
    public function labaRugi(string $dari, string $sampai): object
    {
        $baris = $this->saldoAkun($dari, $sampai);

        $pendapatan = $baris->filter(
            fn (object $b) => Account::golonganDari($b->type) === Account::GOL_PENDAPATAN
        )->values();

        $beban = $baris->filter(
            fn (object $b) => Account::golonganDari($b->type) === Account::GOL_BEBAN
        )->values();

        $totalPendapatan = round((float) $pendapatan->sum('saldo'), 2);
        $totalBeban = round((float) $beban->sum('saldo'), 2);

        return (object) [
            'dari' => $dari,
            'sampai' => $sampai,
            'pendapatan' => $pendapatan,
            'beban' => $beban,
            'total_pendapatan' => $totalPendapatan,
            'total_beban' => $totalBeban,
            'surplus' => round($totalPendapatan - $totalBeban, 2),
            'belum_dijurnal' => $pendapatan->isEmpty() && $beban->isEmpty(),
        ];
    }

    /**
     * Saldo tiap akun, disajikan menurut ARAH NORMALNYA.
     *
     * Akun debit-normal (aset, beban) bersaldo debit dikurangi kredit;
     * akun kredit-normal (kewajiban, modal, pendapatan) sebaliknya. Tanpa
     * pembalikan ini setiap akun pendapatan tampil negatif, dan pembacanya
     * menyimpulkan ada yang rusak — padahal cuma tandanya yang terbalik.
     *
     * $dari null berarti SEJAK AWAL PEMBUKUAN: itulah yang dibutuhkan
     * neraca, dan bedanya dengan laba-rugi bukan soal rasa.
     *
     * @return Collection<int, object>
     */
    private function saldoAkun(?string $dari, string $sampai): Collection
    {
        $q = DB::table('finance.journal_lines as l')
            ->join('finance.journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->join('finance.chart_of_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('j.entry_date', '<=', $sampai);

        if ($dari !== null) {
            $q->where('j.entry_date', '>=', $dari);
        }

        return $q->groupBy('a.id', 'a.code', 'a.name', 'a.type')
            ->selectRaw('a.code, a.name, a.type,
                         sum(l.debit) AS debit, sum(l.credit) AS credit')
            ->orderBy('a.code')
            ->get()
            ->map(function (object $b) {
                $debit = (float) $b->debit;
                $kredit = (float) $b->credit;

                $b->saldo = round(
                    Account::isDebitNormalUntuk($b->type) ? $debit - $kredit : $kredit - $debit,
                    2
                );

                $b->golongan = Account::golonganDari($b->type);

                return $b;
            })
            /*
             * Akun bersaldo nol dibuang dari penyajian, BUKAN dari
             * hitungan — totalnya sudah terjumlah sebelum ini. Akun yang
             * pernah dipakai lalu kembali nol tidak menambah keterangan
             * apa pun, dan daftar panjang berisi nol membuat pembaca
             * berhenti membacanya.
             */
            ->filter(fn (object $b) => $b->saldo !== 0.0)
            ->values();
    }

    /** Akumulasi pendapatan dikurangi beban sampai satu tanggal. */
    private function surplusSampai(string $tanggal): float
    {
        $baris = $this->saldoAkun(null, $tanggal);

        $pendapatan = (float) $baris->filter(
            fn (object $b) => Account::golonganDari($b->type) === Account::GOL_PENDAPATAN
        )->sum('saldo');

        $beban = (float) $baris->filter(
            fn (object $b) => Account::golonganDari($b->type) === Account::GOL_BEBAN
        )->sum('saldo');

        return round($pendapatan - $beban, 2);
    }
}
