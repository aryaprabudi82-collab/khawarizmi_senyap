<?php

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

/**
 * Menentukan layar pertama yang dilihat seseorang setelah masuk.
 *
 * MENGAPA INI ADA. Beranda dulu selalu mengalihkan ke pendaftaran pasien,
 * siapa pun yang masuk — sehingga setiap peran yang tidak berhak
 * mendaftarkan pasien menabrak 403 pada detik pertama, sesudah
 * memasukkan kata sandi yang benar. Manajemen, apoteker, petugas dapur,
 * petugas aset, pustakawan: semuanya.
 *
 * DAFTAR TUJUANNYA TIDAK DITULIS TANGAN, dan itu keputusan yang penting.
 * Percobaan pertama memakai daftar hak-ke-rute yang ditulis manual, dan
 * daftar seperti itu benar pada hari ditulis lalu menua diam-diam: modul
 * berikutnya menambah layar, tidak ada yang ingat menambahkannya ke
 * daftar, dan perannya kembali mendarat di halaman kosong tanpa satu pun
 * galat. Di sini daftarnya DISUSUN dari rute yang sungguh terdaftar,
 * sehingga layar baru yang bergerbang otomatis ikut jadi tujuan yang sah.
 *
 * URUTAN KEUTAMAAN tetap ditulis tangan, karena itu memang keputusan:
 * seorang kasir yang kebetulan juga boleh melihat laporan seharusnya
 * mendarat di kasir, bukan di laporan. Yang tidak disebut di daftar
 * keutamaan tetap dipakai — hanya saja sesudah semua yang disebut.
 */
class LandingResolver
{
    /**
     * Hak yang paling mewakili pekerjaan sehari-hari, berurutan.
     *
     * Yang di atas menang. Ini satu-satunya bagian yang perlu disunting
     * manusia, dan isinya sedikit karena memang hanya alur kerja utama
     * yang butuh diatur urutannya.
     *
     * @var list<string>
     */
    private const KEUTAMAAN = [
        'registrasi',
        'pembayaran_ralan',
        'penilaian_awal_medis_ralan',
        'tindakan_ranap',
        'resep_obat',
        'telaah_resep',
        'beri_obat',
        'periksa_lab',
        'periksa_radiologi',
        'pemeriksaan_lab_pa',
        'pendapatan_per_akun',
        'rekap_kunjungan',
        'pegawai_user',
        'aplikasi',
        'user',
    ];

    /**
     * Layar yang haknya DIPERIKSA DI DALAM CONTROLLER, bukan lewat
     * middleware `can:` — jadi tidak terlihat oleh sapuan rute.
     *
     * Justru layar-layar terpenting yang begini, dan bukan kebetulan:
     * satu layar melayani beberapa kapabilitas sekaligus sementara
     * middleware `can:` hanya menerima satu kode. Kasir melayani ralan dan
     * ranap; antrean resep melayani dokter penulis, apoteker penelaah, dan
     * apoteker penyerah; order penunjang melayani lab, radiologi, dan PA.
     *
     * Tanpa daftar ini seorang kasir mendarat di layar piutang alih-alih
     * layar kasir, dan seorang apoteker mendarat di halaman kosong —
     * keduanya sungguh terjadi dan ketahuan saat menelusuri lewat browser.
     *
     * @var array<string, string>
     */
    private const DIPERIKSA_DI_CONTROLLER = [
        'pembayaran_ralan' => 'tagihan.index',
        'pembayaran_ranap' => 'tagihan.index',
        'resep_obat' => 'resep.index',
        'telaah_resep' => 'resep.index',
        'beri_obat' => 'resep.index',
        /*
         * Layar order berparameter kategori, dan kategorinya DITENTUKAN
         * OLEH HAKNYA: petugas lab mendarat di antrean lab, petugas
         * radiologi di antrean radiologi. Mengantar keduanya ke kategori
         * yang sama berarti salah satunya membuka antrean milik unit lain
         * setiap pagi.
         */
        'periksa_lab' => ['order.index', ['kategori' => 'lab']],
        'periksa_radiologi' => ['order.index', ['kategori' => 'radiologi']],
        'pemeriksaan_lab_pa' => ['order.index', ['kategori' => 'pa']],

        /*
         * Bukan diperiksa di controller, tapi perlu disebut karena DUA
         * layar digerbangi kode yang sama dan sapuan memilih yang lebih
         * dulu terdaftar. Yang dicari seorang manajemen saat masuk adalah
         * keadaan keuangan rumah sakit, bukan formulir pemetaan akun.
         */
        'pendapatan_per_akun' => 'keuangan.index',
    ];

    public static function untuk(?User $pengguna): RedirectResponse|Response
    {
        if ($pengguna === null) {
            return redirect()->route('masuk');
        }

        $peta = self::petaHakKeRute();

        foreach (self::KEUTAMAAN as $hak) {
            if (isset($peta[$hak]) && $pengguna->can($hak)) {
                return self::alihkan($peta[$hak]);
            }
        }

        // Sisanya, urut abjad supaya hasilnya tidak berubah-ubah antar
        // penggelaran — tujuan beranda yang berpindah sendiri membuat
        // petugas mengira sistemnya berubah.
        foreach ($peta as $hak => $rute) {
            if ($pengguna->can($hak)) {
                return self::alihkan($rute);
            }
        }

        return response()->view('beranda-kosong');
    }

    /**
     * Tujuan boleh berupa nama rute saja, atau [nama, parameter] untuk
     * rute yang menuntut parameter — layar order, misalnya, yang
     * kategorinya ditentukan oleh hak penggunanya.
     *
     * @param  string|array{0: string, 1: array<string, mixed>}  $tujuan
     */
    private static function alihkan(string|array $tujuan): RedirectResponse
    {
        return is_array($tujuan)
            ? redirect()->route($tujuan[0], $tujuan[1])
            : redirect()->route($tujuan);
    }

    /**
     * Hak akses -> nama rute layar pembukanya, disusun dari rute nyata.
     *
     * Hanya rute GET tanpa parameter yang diambil: yang berparameter
     * butuh sesuatu untuk dibuka (satu resep, satu pasien) dan tidak bisa
     * jadi halaman pertama. Rute cetak juga dilewati — mengantar orang ke
     * halaman cetak begitu ia masuk adalah kejutan, bukan pelayanan.
     *
     * @return array<string, string>
     */
    private static function petaHakKeRute(): array
    {
        $peta = [];

        foreach (Route::getRoutes() as $rute) {
            $nama = $rute->getName();

            if ($nama === null || str_contains($rute->uri(), '{')) {
                continue;
            }

            if (! in_array('GET', $rute->methods(), true)) {
                continue;
            }

            if (str_contains($nama, 'cetak') || str_contains($nama, 'unduh')) {
                continue;
            }

            foreach ($rute->gatherMiddleware() as $m) {
                if (! is_string($m) || ! str_starts_with($m, 'can:')) {
                    continue;
                }

                $hak = substr($m, 4);

                // Yang pertama ditemukan menang: rute index sebuah modul
                // terdaftar lebih dulu daripada sub-layarnya.
                $peta[$hak] ??= $nama;
            }
        }

        ksort($peta);

        /*
         * Yang diperiksa di controller MENANG atas hasil sapuan: kalau
         * sebuah hak kebetulan juga menggerbangi layar lain, layar
         * utamanyalah yang dimaksud. `pembayaran_ralan` misalnya ikut
         * menggerbangi tombol "Buka Tagihan" di layar resep — tapi tempat
         * seorang kasir seharusnya mendarat tetap layar kasir.
         */
        foreach (self::DIPERIKSA_DI_CONTROLLER as $hak => $tujuan) {
            $nama = is_array($tujuan) ? $tujuan[0] : $tujuan;

            if (Route::has($nama)) {
                $peta[$hak] = $tujuan;
            }
        }

        return $peta;
    }
}
