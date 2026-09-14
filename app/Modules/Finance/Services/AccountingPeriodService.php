<?php

namespace App\Modules\Finance\Services;

use App\Modules\Platform\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Kalender periode akuntansi — Modul A butir 1.9.
 *
 * EMPAT KEADAAN, DAN MASING-MASING MENJAWAB KEBUTUHAN NYATA:
 *
 *   terbuka     — transaksi harian berjalan normal.
 *   soft-close  — transaksi operasional ditutup, jurnal KOREKSI masih
 *                 boleh masuk. Inilah beberapa hari di awal bulan ketika
 *                 bagian keuangan merapikan angka bulan lalu.
 *   tertutup    — tidak ada yang boleh masuk; laporan sudah disusun.
 *   terkunci    — laporan sudah dikirim ke luar. Tidak bisa dibuka lagi
 *                 lewat aplikasi.
 *
 * TANPA SOFT-CLOSE, pilihannya cuma dua dan keduanya buruk: biarkan
 * terbuka — lalu transaksi baru terus masuk ke bulan lalu; atau tutup
 * sekarang — lalu koreksinya tidak bisa dikerjakan.
 *
 * PERPINDAHAN DITEGAKKAN, TIDAK BEBAS. Periode tidak boleh melompat dari
 * terbuka langsung ke terkunci: mengunci berarti menyatakan laporannya
 * sudah dikirim, dan laporan tidak bisa dikirim dari periode yang belum
 * pernah ditutup. Urutan yang bisa dilompati membuat "terkunci" berhenti
 * berarti apa-apa.
 *
 * TERKUNCI TIDAK BISA DIBUKA LEWAT APLIKASI. Ini satu-satunya keadaan
 * yang tidak punya jalan mundur di sini, dan itu disengaja: yang sudah
 * keluar rumah sakit tidak boleh berubah diam-diam. Membukanya harus jadi
 * keputusan yang meninggalkan jejak di luar sistem juga — berita acara,
 * persetujuan tertulis — bukan satu tombol.
 */
class AccountingPeriodService
{
    public const TERBUKA = 'terbuka';

    public const SOFT_CLOSE = 'soft-close';

    public const TERTUTUP = 'tertutup';

    public const TERKUNCI = 'terkunci';

    /**
     * Perpindahan yang SAH. Yang tidak tercantum ditolak.
     *
     * @var array<string, list<string>>
     */
    private const PERPINDAHAN = [
        self::TERBUKA => [self::SOFT_CLOSE, self::TERTUTUP],
        self::SOFT_CLOSE => [self::TERBUKA, self::TERTUTUP],
        self::TERTUTUP => [self::TERBUKA, self::SOFT_CLOSE, self::TERKUNCI],
        self::TERKUNCI => [],
    ];

    /**
     * Membuat periode satu tahun penuh.
     *
     * Dibuat LEBIH DULU, tidak lahir sendiri saat ada transaksi. Periode
     * yang lahir otomatis berarti transaksi bertanggal 2031 akan membuat
     * periodenya sendiri dan diterima tanpa pertanyaan — dan salah ketik
     * tahun adalah kesalahan yang paling sering terjadi di loket.
     *
     * Idempoten: menjalankannya dua kali tidak menggandakan apa pun.
     */
    public function siapkanTahun(int $tahun): int
    {
        $dibuat = 0;

        for ($bulan = 1; $bulan <= 12; $bulan++) {
            $awal = sprintf('%04d-%02d-01', $tahun, $bulan);
            $akhir = date('Y-m-t', strtotime($awal));

            $terpengaruh = DB::table('finance.accounting_periods')->insertOrIgnore([
                'period_year' => $tahun,
                'period_month' => $bulan,
                'starts_on' => $awal,
                'ends_on' => $akhir,
                'status' => self::TERBUKA,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $dibuat += $terpengaruh;
        }

        return $dibuat;
    }

    /**
     * Memindahkan keadaan sebuah periode.
     *
     * @throws FinanceException
     */
    public function ubahStatus(int $tahun, int $bulan, string $status, User $aktor, ?string $alasan = null): object
    {
        $periode = $this->periode($tahun, $bulan)
            ?? throw new FinanceException(
                sprintf('Periode %04d-%02d belum terdaftar di kalender akuntansi. '
                    .'Siapkan tahunnya lebih dulu lewat siapkanTahun().', $tahun, $bulan)
            );

        if ($periode->status === $status) {
            throw new FinanceException(
                sprintf('Periode %04d-%02d sudah berstatus %s.', $tahun, $bulan, $status)
            );
        }

        $boleh = self::PERPINDAHAN[$periode->status] ?? [];

        if (! in_array($status, $boleh, true)) {
            throw new FinanceException($this->pesanPerpindahanTerlarang($periode->status, $status, $tahun, $bulan));
        }

        /*
         * ALASAN WAJIB untuk setiap keadaan selain terbuka. Periode yang
         * dikunci tanpa keterangan tidak bisa dijelaskan kepada auditor
         * yang bertanya kenapa bulan itu tidak bisa disentuh lagi.
         */
        if ($status !== self::TERBUKA && trim((string) $alasan) === '') {
            throw new FinanceException(
                "Perpindahan ke status '{$status}' wajib menyebutkan alasannya."
            );
        }

        DB::table('finance.accounting_periods')
            ->where('id', $periode->id)
            ->update([
                'status' => $status,
                'status_changed_at' => now(),
                'status_changed_by' => $aktor->id,
                'status_reason' => $alasan === null ? null : trim($alasan),
                'updated_at' => now(),
            ]);

        return $this->periode($tahun, $bulan);
    }

    public function periode(int $tahun, int $bulan): ?object
    {
        return DB::table('finance.accounting_periods')
            ->where('period_year', $tahun)
            ->where('period_month', $bulan)
            ->first();
    }

    /** Status periode pada sebuah tanggal; null bila periodenya belum terdaftar. */
    public function statusPada(string $tanggal): ?string
    {
        return $this->periode((int) date('Y', strtotime($tanggal)), (int) date('n', strtotime($tanggal)))?->status;
    }

    /**
     * Apakah sebuah jurnal boleh masuk pada tanggal itu.
     *
     * $bersumberTransaksi membedakan jurnal operasional dari jurnal
     * koreksi manual — itu yang menentukan apakah soft-close
     * meloloskannya.
     */
    public function bolehDijurnal(string $tanggal, bool $bersumberTransaksi = true): bool
    {
        $status = $this->statusPada($tanggal);

        return match ($status) {
            null, self::TERBUKA => true,
            self::SOFT_CLOSE => ! $bersumberTransaksi,
            default => false,
        };
    }

    /**
     * Periode yang belum terdaftar pada rentang tahun tertentu.
     *
     * Dipakai siap:periksa. Kalender yang bolong tidak menghentikan
     * sistem — trigger meloloskan periode tak terdaftar supaya sistem
     * yang sudah berjalan tidak mati demi kalender yang belum diisi —
     * tapi ia berarti tutup buku tidak bisa dijalankan untuk bulan itu.
     *
     * @return Collection<int, string>
     */
    public function bulanBelumTerdaftar(int $dariTahun, int $sampaiTahun): Collection
    {
        $ada = DB::table('finance.accounting_periods')
            ->whereBetween('period_year', [$dariTahun, $sampaiTahun])
            ->get()
            ->map(fn ($p) => sprintf('%04d-%02d', $p->period_year, $p->period_month))
            ->all();

        $hilang = collect();

        for ($t = $dariTahun; $t <= $sampaiTahun; $t++) {
            for ($b = 1; $b <= 12; $b++) {
                $kunci = sprintf('%04d-%02d', $t, $b);

                if (! in_array($kunci, $ada, true)) {
                    $hilang->push($kunci);
                }
            }
        }

        return $hilang;
    }

    private function pesanPerpindahanTerlarang(string $dari, string $ke, int $tahun, int $bulan): string
    {
        $periode = sprintf('%04d-%02d', $tahun, $bulan);

        if ($dari === self::TERKUNCI) {
            return "Periode {$periode} sudah TERKUNCI dan tidak bisa dibuka lewat aplikasi. "
                .'Laporannya sudah dikirim ke luar rumah sakit, dan yang sudah keluar tidak boleh '
                .'berubah diam-diam. Pembukaannya harus jadi keputusan yang meninggalkan jejak di '
                .'luar sistem juga — berita acara dan persetujuan tertulis — lalu dikerjakan '
                .'administrator basis data, bukan lewat satu tombol di layar.';
        }

        if ($ke === self::TERKUNCI) {
            return "Periode {$periode} berstatus '{$dari}' dan tidak bisa langsung dikunci. "
                .'Mengunci berarti menyatakan laporannya sudah dikirim, dan laporan tidak bisa '
                .'dikirim dari periode yang belum pernah ditutup. Tutup lebih dulu.';
        }

        return "Periode {$periode} tidak bisa berpindah dari '{$dari}' ke '{$ke}'.";
    }
}
