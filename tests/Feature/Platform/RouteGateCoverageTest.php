<?php

namespace Tests\Feature\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Setiap rute terautentikasi harus punya gerbang — atau tercatat kenapa tidak.
 *
 * MENGAPA UJI INI ADA. Dua lubang nyata ditemukan saat verifikasi domain M
 * dan I, dan keduanya berbentuk sama: berkas rute TERBACA seolah tergerbang,
 * sementara yang berlaku tidak ada gerbang sama sekali.
 *
 *  1. Rekam medis. Gerbangnya dipasang `})->middleware(...)` di UJUNG grup —
 *     dan middleware yang dipasang setelah group() tidak menyentuh rute yang
 *     sudah terdaftar. Kesebelas rutenya berjalan dengan `web | auth` saja,
 *     jadi setiap pengguna terautentikasi bisa membuka rekam medis pasien,
 *     menulis asesmen, dan menghapus diagnosis.
 *
 *  2. Resep. Berkas rutenya memuat komentar "apoteker maupun dokter penulis"
 *     lalu tidak memasang apa pun. Yang berlaku bukan maksudnya melainkan
 *     ketiadaannya: siapa pun bisa membaca seluruh resep rumah sakit dan
 *     membatalkan resep siapa pun.
 *
 * SELURUH 2.096 UJI LAIN TETAP HIJAU pada kedua kasus. Uji per konteks selalu
 * memakai pengguna yang MEMANG berhak, jadi tidak satu pun pernah menanyakan
 * apa yang terjadi bila yang membuka adalah orang yang tidak berhak.
 *
 * Uji ini memeriksa MIDDLEWARE YANG SUNGGUH TERPASANG lewat gatherMiddleware(),
 * bukan yang tertulis di berkas rute — persis perbedaan yang membuat lubang
 * pertama tidak terlihat selama berbulan-bulan.
 */
class RouteGateCoverageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Rute terautentikasi yang sah tanpa gerbang `can:`, berikut alasannya.
     *
     * Daftar ini adalah izin yang diberikan dengan sadar, bukan tempat
     * membuang rute yang merepotkan. Menambah baris di sini berarti
     * menyatakan bahwa otorisasinya SUNGGUH dikerjakan di tempat lain —
     * dan menyebut di mana, supaya bisa diperiksa orang berikutnya.
     *
     * @var array<string, string>
     */
    private const DIIZINKAN_TANPA_GERBANG = [
        // Halaman awal & keluar: tidak menampilkan data pasien apa pun.
        'GET /' => 'Beranda; hanya menampilkan pintasan sesuai hak pengguna.',
        'POST keluar' => 'Logout.',

        // Diperiksa imperatif karena kodenya bergantung isi baris.
        'GET registrasi/{registrasi}/barcode' => 'BarcodeController::print() memilih barcoderalan/barcoderanap menurut '
            .'care_type kunjungan — middleware can: hanya menerima satu kode.',

        // Satu layar melayani dua/tiga kapabilitas; middleware can: hanya
        // menerima satu, jadi diperiksa per aksi di controller.
        'GET tagihan' => 'InvoiceController::assertAccess() per care_type.',
        'GET tagihan/{tagihan}' => 'InvoiceController::assertAccess() per care_type.',
        'POST tagihan/kunjungan/{registrasi}' => 'InvoiceController::assertAccess().',
        'POST tagihan/{tagihan}/bayar' => 'InvoiceController::assertAccess().',
        'POST tagihan/{tagihan}/segarkan' => 'InvoiceController::assertAccess().',
        'POST tagihan/{tagihan}/batal' => 'InvoiceController::assertAccess().',
        'POST tagihan/pembayaran/{pembayaran}/batal' => 'InvoiceController::assertAccess().',

        'GET order/{kategori}' => 'OrderController::assertAccess() per kategori.',
        'GET order/{kategori}/{order}' => 'OrderController::assertAccess() per kategori.',
        'GET order/{kategori}/cari' => 'OrderController::assertAccess() per kategori.',
        'POST order/{kategori}/kunjungan/{registrasi}' => 'OrderController::assertAccess().',
        'POST order/{kategori}/{order}/item' => 'OrderController::assertAccess().',
        'POST order/{kategori}/{order}/proses' => 'OrderController::assertAccess().',
        'POST order/{kategori}/{order}/verifikasi' => 'OrderController::assertAccess().',
        'POST order/{kategori}/{order}/batal' => 'OrderController::assertAccess().',
        'POST order/{kategori}/item/{item}/hasil' => 'OrderController::assertAccess().',
        'DELETE order/{kategori}/item/{item}' => 'OrderController::assertAccess().',

        'GET resep' => 'PrescriptionController::assertAccess() — resep_obat/telaah_resep/beri_obat.',
        'GET resep/{resep}' => 'PrescriptionController::assertAccess().',
        'GET resep/cari-obat' => 'PrescriptionController::assertAccess().',
        'POST resep/{resep}/batal' => 'PrescriptionController::assertAccess().',

        'GET lab-kesling/pengujian' => 'SampleTestController::assertCanView().',
        'GET lab-kesling/pengujian/{pengujian}' => 'SampleTestController::assertCanView().',

        'GET rawat-inap' => 'AdmissionController::index() memeriksa tindakan_ranap ATAU diet_pasien.',
    ];

    #[Test]
    public function setiap_rute_terautentikasi_tergerbang_atau_tercatat_alasannya(): void
    {
        $telanjang = [];

        foreach (Route::getRoutes() as $rute) {
            $middleware = $rute->gatherMiddleware();

            if (! in_array('auth', $middleware, true)) {
                continue;
            }

            foreach ($middleware as $m) {
                if (is_string($m) && str_starts_with($m, 'can:')) {
                    continue 2;
                }
            }

            $metode = implode('|', array_diff($rute->methods(), ['HEAD']));
            $kunci = $metode.' '.$rute->uri();

            if (array_key_exists($kunci, self::DIIZINKAN_TANPA_GERBANG)) {
                continue;
            }

            $telanjang[] = $kunci.'  ->  '.str_replace('App\\Modules\\', '', $rute->getActionName());
        }

        $this->assertSame([], $telanjang,
            count($telanjang).' rute terautentikasi TIDAK punya gerbang can: dan tidak '
            ."tercatat alasannya:\n  ".implode("\n  ", $telanjang)
            ."\n\nSetiap pengguna yang berhasil masuk bisa mengaksesnya, apa pun perannya. "
            .'Pasang gerbangnya, ATAU daftarkan di DIIZINKAN_TANPA_GERBANG dengan menyebut '
            .'di mana otorisasinya sungguh dikerjakan — jangan biarkan maksud yang cuma '
            .'tertulis di komentar.');
    }

    #[Test]
    public function daftar_izin_tidak_menyimpan_rute_yang_sudah_tidak_ada(): void
    {
        $adaSekarang = [];

        foreach (Route::getRoutes() as $rute) {
            $metode = implode('|', array_diff($rute->methods(), ['HEAD']));
            $adaSekarang[$metode.' '.$rute->uri()] = true;
        }

        $usang = array_values(array_filter(
            array_keys(self::DIIZINKAN_TANPA_GERBANG),
            fn (string $k) => ! isset($adaSekarang[$k])
        ));

        /*
         * Daftar izin yang menyimpan rute mati akan tumbuh jadi tempat
         * sampah, dan tempat sampah yang panjang membuat orang berhenti
         * membacanya — lalu izin berikutnya masuk tanpa diperiksa.
         */
        $this->assertSame([], $usang,
            "Daftar izin memuat rute yang sudah tidak ada:\n  ".implode("\n  ", $usang));
    }

    #[Test]
    public function middleware_gerbang_tidak_dipasang_setelah_group(): void
    {
        /*
         * Menangkap SEBABNYA, bukan cuma akibatnya. `})->middleware(...)`
         * di ujung sebuah grup rute tidak berlaku sama sekali — group()
         * sudah mendaftarkan rutenya lebih dulu — tapi berkasnya terbaca
         * persis seperti gerbang yang sah.
         *
         * Uji di atas menangkap akibatnya (rute jadi telanjang). Uji ini
         * menangkap bentuk penulisannya, supaya yang menulisnya tahu apa
         * yang salah tanpa harus menelusuri rute satu per satu.
         */
        $pelanggaran = [];

        foreach (glob(app_path('Modules/*/Routes/web.php')) ?: [] as $berkas) {
            /*
             * KOMENTAR DIBUANG DULU lewat tokenizer, bukan dicocokkan pada
             * teks mentah. Percobaan pertama memakai regex atas isi berkas
             * apa adanya, dan langsung menghasilkan positif palsu: komentar
             * yang MENJELASKAN bug ini ikut tercocok. Uji yang menuduh
             * penjelasan sebagai pelanggaran akan dimatikan orang, bukan
             * diperbaiki.
             */
            $kode = '';

            foreach (token_get_all((string) file_get_contents($berkas)) as $token) {
                if (is_array($token)) {
                    if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }

                    $kode .= $token[1];

                    continue;
                }

                $kode .= $token;
            }

            if (preg_match_all('/\}\)\s*->\s*middleware\(/', $kode, $m)) {
                $pelanggaran[] = basename(dirname($berkas, 2)).'/Routes/web.php ('
                    .count($m[0]).'x)';
            }
        }

        $this->assertSame([], $pelanggaran,
            "Middleware dipasang SETELAH group() di berkas berikut:\n  "
            .implode("\n  ", $pelanggaran)
            ."\n\n`})->middleware(...)` tidak berlaku: group() sudah mendaftarkan "
            .'rutenya lebih dulu. Pindahkan middleware-nya ke DEPAN, sebelum ->group().');
    }
}
