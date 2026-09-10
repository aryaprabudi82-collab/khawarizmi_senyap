<?php

namespace App\Modules\Pharmacy\Http\Controllers;

use App\Modules\Pharmacy\Models\Prescription;
use Illuminate\View\View;

/**
 * Layar antrean apotek (Khanza `display_apotek`, domain U).
 *
 * TIDAK ADA TABEL BARU. Status resep sudah tercatat pada
 * `pharmacy.prescriptions` sejak konteks ini dibangun — ditulis,
 * menunggu-telaah, disetujui, diserahkan. Yang belum ada cuma layarnya.
 *
 * TIGA KELOMPOK, BUKAN SATU DAFTAR PANJANG. Yang menunggu di apotek
 * menanyakan satu hal: apakah obat saya sudah bisa diambil. Daftar berurut
 * nomor tidak menjawab itu; tiga kelompok — sedang disiapkan, siap diambil,
 * sudah diserahkan — menjawabnya tanpa perlu bertanya ke petugas.
 *
 * NAMA DISAMARKAN, alasannya sama dengan layar antrean poliklinik: layar
 * ini menghadap ruang tunggu. Di apotek alasannya bahkan lebih tajam —
 * nama yang tertera di sebelah antrean apotek memberitahu bahwa orang itu
 * sedang menebus obat, dan pada apotek rumah sakit jiwa atau klinik VCT
 * itu sudah cukup untuk membuka diagnosisnya.
 */
class PharmacyQueueDisplayController
{
    public function index(): View
    {
        /*
         * `prescribed_at`, bukan `created_at` — dan rentang, bukan
         * whereDate. Dua alasan yang keduanya penting pada layar ini:
         *
         *  1. `prescribed_at` adalah waktu resep DITULIS dokter, yang
         *     memang jadi urutan antrean; `created_at` cuma kapan barisnya
         *     masuk basis data, dan keduanya berbeda pada resep yang
         *     dicatat susulan.
         *  2. Indeksnya ada di (status, prescribed_at). `whereDate`
         *     membungkus kolom dalam cast dan mematikan indeks itu —
         *     diukur dengan EXPLAIN: Seq Scan. Layar ini menyegarkan diri
         *     setiap dua puluh detik, jadi sekali pindai penuh atas jutaan
         *     baris resep berlipat jadi ribuan kali sehari.
         */
        $hariIni = Prescription::query()
            ->whereOnDate('prescribed_at', now())
            ->whereNotIn('status', ['batal', 'ditolak'])
            ->orderBy('prescribed_at')
            ->get();

        return view('pharmacy::display.antrean', [
            'disiapkan' => $hariIni->whereIn('status', ['ditulis', 'menunggu-telaah'])->values(),
            'siap' => $hariIni->where('status', 'disetujui')->values(),

            /*
             * Yang sudah diserahkan dibatasi sepuluh terakhir. Daftar penuh
             * sepanjang hari akan mendorong yang siap diambil keluar layar,
             * dan justru itulah satu-satunya kelompok yang orang tunggu.
             */
            'diserahkan' => $hariIni->where('status', 'diserahkan')
                ->sortByDesc('updated_at')->take(10)->values(),

            'tanggal' => now()->toDateString(),
        ]);
    }

    /**
     * Menyamarkan nama untuk layar publik.
     *
     * Disalin, bukan dipinjam dari konteks encounter: sepuluh baris fungsi
     * murni jauh lebih murah daripada ketergantungan lintas konteks yang
     * dibuat cuma untuk memformat teks. Disiplin yang sama dipakai untuk
     * pembaca view yang diterbitkan.
     */
    public static function samarkan(?string $nama): string
    {
        $bagian = preg_split('/\s+/', trim((string) $nama)) ?: [];

        if ($bagian === [] || $bagian[0] === '') {
            return '—';
        }

        $hasil = [array_shift($bagian)];

        foreach ($bagian as $kata) {
            $hasil[] = mb_strtoupper(mb_substr($kata, 0, 1)).'.';
        }

        return implode(' ', $hasil);
    }
}
