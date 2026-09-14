<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\Account;
use App\Modules\ReadinessCheck;
use Illuminate\Support\Facades\DB;

/**
 * Syarat kesiapan konteks finance.
 *
 * MENGAPA BUTIR-BUTIR INI ADA DI SINI, BUKAN DI CATATAN PENGEMBANG.
 * Yang membaca `siap:periksa` adalah orang yang memutuskan apakah rumah
 * sakit boleh mulai beroperasi. Kekurangan yang hanya tercatat di docblock
 * tidak pernah sampai kepadanya — dan laporan keuangan yang tersaji rapi
 * akan dipercaya apa adanya.
 */
class FinanceReadiness implements ReadinessCheck
{
    public function __construct(private readonly AccountingPeriodService $periode) {}

    public function readinessItems(): array
    {
        return [
            $this->butirBaganAkun(),
            $this->butirKalenderPeriode(),
            $this->butirKlasifikasiAkun(),
        ];
    }

    /**
     * @return array{judul: string, status: string, akibat: string}
     */
    private function butirBaganAkun(): array
    {
        $jumlah = Account::query()->where('is_active', true)->count();

        /*
         * AMBANGNYA SEPULUH, dan angka itu bukan tebakan sembarangan:
         * bagan akun rumah sakit mana pun punya puluhan akun. Sepuluh
         * cukup rendah untuk tidak menuntut kelengkapan, cukup tinggi
         * untuk membedakan bagan akun sungguhan dari empat akun contoh
         * seeder.
         */
        $cukup = $jumlah > 10;

        return [
            'judul' => 'Bagan akun RSP UI',
            'status' => $cukup ? self::BERES : self::MENGHALANGI,
            'akibat' => $cukup
                ? $jumlah.' akun aktif terdaftar.'
                : 'Baru '.$jumlah.' akun aktif — itu contoh pengembangan, bukan bagan akun RSP UI. '
                    .'Selama bagan akunnya belum disusun, SELURUH pendapatan dan beban tidak punya '
                    .'tempat jatuh di buku besar: neraca dan laporan aktivitas hanya memuat sebagian '
                    .'kecil transaksi, dan sisanya tidak muncul di mana pun. RSP UI berstatus PTN-BH, '
                    .'jadi bagan akunnya mengikuti PSAK. Disusun bagian keuangan lewat layar Buku Besar.',
        ];
    }

    /**
     * @return array{judul: string, status: string, akibat: string}
     */
    private function butirKalenderPeriode(): array
    {
        $tahunIni = (int) now()->year;
        $hilang = $this->periode->bulanBelumTerdaftar($tahunIni, $tahunIni);

        if ($hilang->isEmpty()) {
            return [
                'judul' => 'Kalender periode akuntansi',
                'status' => self::BERES,
                'akibat' => 'Seluruh 12 bulan '.$tahunIni.' sudah terdaftar.',
            ];
        }

        /*
         * PERINGATAN, BUKAN PENGHALANG. Periode yang belum terdaftar
         * diloloskan trigger — sistem yang sudah berjalan tidak boleh mati
         * demi kalender yang belum diisi siapa pun. Tapi tutup buku tidak
         * bisa dijalankan untuk bulan yang tidak punya periodenya.
         */
        return [
            'judul' => 'Kalender periode akuntansi',
            'status' => self::PERINGATAN,
            'akibat' => $hilang->count().' bulan '.$tahunIni.' belum terdaftar di kalender akuntansi '
                .'('.$hilang->take(3)->implode(', ').($hilang->count() > 3 ? ', dan seterusnya' : '').'). '
                .'Transaksi tetap bisa dijurnalkan — periode tak terdaftar sengaja diloloskan supaya '
                .'pelayanan tidak berhenti — tapi TUTUP BUKU tidak bisa dijalankan untuk bulan itu, '
                .'dan tidak ada yang menahan jurnal masuk ke bulan yang seharusnya sudah ditutup.',
        ];
    }

    /**
     * @return array{judul: string, status: string, akibat: string}
     */
    private function butirKlasifikasiAkun(): array
    {
        $belum = DB::table('finance.chart_of_accounts')
            ->where('is_active', true)
            ->whereNull('klasifikasi')
            ->count();

        if ($belum === 0) {
            return [
                'judul' => 'Klasifikasi akun PSAK',
                'status' => self::BERES,
                'akibat' => 'Seluruh akun aktif sudah punya klasifikasi laporan.',
            ];
        }

        return [
            'judul' => 'Klasifikasi akun PSAK',
            'status' => self::PERINGATAN,
            'akibat' => $belum.' akun aktif belum punya klasifikasi PSAK (aset lancar / tidak lancar, '
                .'liabilitas jangka pendek / panjang, ekuitas, pendapatan, beban). Akun tanpa '
                .'klasifikasi TIDAK PUNYA TEMPAT di Laporan Posisi Keuangan — saldonya tetap benar '
                .'di buku besar, tapi hilang dari laporan yang dikirim ke luar, dan laporannya tetap '
                .'tampak seimbang.',
        ];
    }
}
