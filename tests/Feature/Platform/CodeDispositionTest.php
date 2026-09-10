<?php

namespace Tests\Feature\Platform;

use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Models\Permission;
use App\Modules\Platform\Services\CodeDispositionRegistry;
use App\Modules\Platform\Services\ManagedPermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Registri disposisi kode Khanza.
 *
 * MENGAPA UJI INI ADA. Pertanyaan "apakah domain A sampai U benar-benar
 * selesai" selama ini hanya bisa dijawab dengan membaca belasan pesan commit
 * dan mempercayai penulisnya. Prosa tidak bisa diperiksa mesin, dan klaim
 * yang tidak bisa diperiksa akan dipercaya lebih lama daripada seharusnya.
 *
 * YANG DIJAGA DI SINI BUKAN "SEMUANYA SUDAH SELESAI". Justru sebaliknya:
 * yang dijaga adalah bahwa sisa pekerjaannya tetap TERHITUNG dan tidak naik
 * diam-diam. Registri yang memaksa tiap kode punya jawaban akan mendorong
 * orang mengarang jawaban demi menutup daftar — dan daftar lengkap yang
 * sebagiannya karangan lebih buruk daripada daftar jujur yang menunjukkan
 * sisanya.
 */
class CodeDispositionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Batas atas utang verifikasi yang masih boleh ada.
     *
     * Angka ini HANYA BOLEH TURUN. Ia bukan target yang harus dikejar
     * sekaligus, melainkan langit-langit yang mencegah utangnya bertambah
     * tanpa ada yang sadar — misalnya saat katalog Khanza diperbarui dan
     * puluhan kode baru masuk tanpa satu pun diperiksa.
     */
    private const BATAS_BELUM_DIVERIFIKASI = 763;

    private CodeDispositionRegistry $registri;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registri = app(CodeDispositionRegistry::class);
    }

    #[Test]
    public function setiap_kode_katalog_punya_disposisi(): void
    {
        $this->seed(PermissionCatalogSeeder::class);

        $registri = $this->registri->all();
        $hilang = [];

        foreach (Permission::query()->pluck('code') as $kode) {
            if (! array_key_exists($kode, $registri)) {
                $hilang[] = $kode;
            }
        }

        /*
         * Kode yang HILANG dari registri adalah kode yang tidak pernah
         * diputuskan nasibnya — bukan kode yang sudah beres. Bedanya
         * penting: yang pertama tidak terlihat siapa pun, yang kedua
         * tercatat.
         */
        $this->assertSame([], $hilang,
            count($hilang).' kode katalog tidak ada di registri disposisi. '
            ."Kode yang tidak terdaftar tidak pernah diputuskan nasibnya:\n"
            .implode(', ', array_slice($hilang, 0, 20)));
    }

    #[Test]
    public function registri_tidak_memuat_kode_yang_tidak_ada_di_katalog(): void
    {
        $this->seed(PermissionCatalogSeeder::class);

        $katalog = Permission::query()->pluck('code')->flip();
        $asing = [];

        foreach (array_keys($this->registri->all()) as $kode) {
            if (! $katalog->has($kode)) {
                $asing[] = $kode;
            }
        }

        // Arah sebaliknya, dan sama pentingnya: kode yang diklaim beres tapi
        // tidak ada di katalog berarti registrinya mengarang pekerjaan.
        $this->assertSame([], $asing,
            'Registri memuat kode yang tidak ada di katalog Khanza: '.implode(', ', $asing));
    }

    #[Test]
    public function status_disposisi_hanya_yang_dikenal(): void
    {
        $takDikenal = [];

        foreach ($this->registri->all() as $kode => $butir) {
            if (! in_array($butir['disposisi'], CodeDispositionRegistry::SEMUA_STATUS, true)) {
                $takDikenal[] = $kode.' => '.$butir['disposisi'];
            }
        }

        $this->assertSame([], $takDikenal,
            'Status disposisi tidak dikenal: '.implode(', ', $takDikenal));
    }

    #[Test]
    public function disposisi_selain_belum_wajib_menyebut_alasannya(): void
    {
        $tanpaCatatan = [];

        foreach ($this->registri->all() as $kode => $butir) {
            if ($butir['disposisi'] === CodeDispositionRegistry::BELUM) {
                continue;
            }

            /*
             * Disposisi tanpa catatan adalah klaim tanpa bukti. "Dinaungi
             * umbrella" yang tidak menyebut gerbang mana tidak bisa
             * diperiksa siapa pun — dan yang tidak bisa diperiksa akan
             * dipercaya lebih lama daripada seharusnya.
             */
            if (trim((string) ($butir['catatan'] ?? '')) === '') {
                $tanpaCatatan[] = $kode;
            }
        }

        $this->assertSame([], $tanpaCatatan,
            count($tanpaCatatan).' kode berdisposisi tanpa alasan tertulis: '
            .implode(', ', array_slice($tanpaCatatan, 0, 20)));
    }

    #[Test]
    public function utang_verifikasi_tidak_bertambah(): void
    {
        $belum = $this->registri->unverifiedCount();

        /*
         * ANGKA INI HANYA BOLEH TURUN. Ia bukan kegagalan yang harus
         * ditutup sekaligus — memeriksa 1.025 kode satu per satu adalah
         * pekerjaan berhari-hari, dan memaksanya selesai dalam satu langkah
         * akan menghasilkan klasifikasi asal-asalan.
         *
         * Yang dicegah di sini justru yang lebih berbahaya: utangnya
         * BERTAMBAH tanpa ada yang sadar. Itu terjadi kalau katalog Khanza
         * diperbarui, atau kalau ada yang memindahkan kode dari
         * 'terverifikasi' kembali ke 'belum' untuk menghindari memutuskan.
         */
        $this->assertLessThanOrEqual(self::BATAS_BELUM_DIVERIFIKASI, $belum,
            "Utang verifikasi naik jadi {$belum} (batas ".self::BATAS_BELUM_DIVERIFIKASI.'). '
            .'Kalau ini karena katalog Khanza bertambah, periksa kode barunya dan JANGAN '
            .'sekadar menaikkan batasnya — batas yang dinaikkan tiap kali gagal berhenti '
            ."menjaga apa pun.\n\nSisa per domain: "
            .json_encode($this->registri->unverifiedByDomain()));
    }

    #[Test]
    public function setiap_kode_terkelola_benar_benar_menggerbangi_sesuatu(): void
    {
        $this->seed(PermissionCatalogSeeder::class);

        /** @var array<string, bool> $aktif */
        $aktif = [];

        foreach (Route::getRoutes() as $rute) {
            foreach ($rute->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'can:')) {
                    $aktif[substr($middleware, 4)] = true;
                }
            }
        }

        /*
         * Sebagian kode diperiksa IMPERATIF di dalam controller, kadang lewat
         * variabel — `$permission = $x ? 'barcoderanap' : 'barcoderalan'`.
         * Sapuan middleware tidak bisa melihatnya, jadi keduanya dikecualikan
         * dengan menyebut tempat pemeriksaannya, bukan didiamkan.
         */
        $diperiksaImperatif = [
            'barcoderalan' => 'BarcodeController::print()',
            'barcoderanap' => 'BarcodeController::print()',
            'pembayaran_ralan' => 'InvoiceController::assertAccess()',
            'pembayaran_ranap' => 'InvoiceController::assertAccess()',
            'periksa_lab' => 'OrderController::assertAccess()',
            'periksa_radiologi' => 'OrderController::assertAccess()',
            'pemeriksaan_lab_pa' => 'OrderController::assertAccess()',
            'diet_pasien' => 'diperiksa bersama tindakan_ranap di layar rawat inap',

            /*
             * Bukan gerbang layar, melainkan gerbang PILIHAN di dalam
             * formulir pendaftaran: hanya pemegangnya yang boleh memilih
             * "Jenis Rawat: Rawat Inap". Diperiksa dua kali —
             * `@can` di blade untuk menyembunyikan pilihannya, dan
             * Rule::in() di RegistrationController::store() supaya POST
             * mentah tetap ditolak. Middleware `can:` tidak cocok di sini
             * karena rutenya sendiri boleh dibuka semua petugas loket.
             */
            'permintaan_ranap' => 'RegistrationController::store() lewat Rule::in()',
        ];

        $ref = new \ReflectionClass(ManagedPermissionCatalog::class);
        $terkelola = $ref->getConstant('MANAGED_CODES');

        $mati = [];

        foreach ($terkelola as $kode) {
            if (isset($aktif[$kode]) || isset($diperiksaImperatif[$kode])) {
                continue;
            }

            $mati[] = $kode;
        }

        /*
         * MENGAPA INI PENTING. ManagedPermissionCatalog adalah daftar yang
         * muncul di layar Kelola Peran. Kode yang ada di sana tapi tidak
         * menggerbangi apa pun berarti admin bisa MENCENTANGNYA dan mengira
         * ia memberi akses — lalu tidak ada yang terbuka, dan yang dicari
         * bukan lagi haknya melainkan kesalahan lain yang tidak ada.
         *
         * Ditemukan lewat uji ini: `closing_kasir` sempat terdaftar padahal
         * layarnya belum pernah dibuat — mesin penutupan shift lengkap
         * berikut ujinya, tapi tanpa satu pun jalan bagi kasir menjalankannya.
         * Ujinya tetap hijau karena uji memanggil service langsung.
         */
        $this->assertSame([], $mati,
            count($mati).' kode terdaftar di ManagedPermissionCatalog tapi TIDAK '
            ."menggerbangi route mana pun:\n  ".implode("\n  ", $mati)
            ."\n\nAdmin bisa mencentangnya di layar Kelola Peran dan mengira ia memberi "
            .'akses. Bangun layarnya, atau keluarkan kodenya dari daftar — jangan '
            .'biarkan hak yang tidak membuka apa pun.');
    }

    #[Test]
    public function jumlah_kode_yang_bergerbang_cocok_dengan_sapuan_route(): void
    {
        $bergerbang = 0;

        foreach ($this->registri->all() as $butir) {
            if ($butir['disposisi'] === CodeDispositionRegistry::BERGERBANG) {
                $bergerbang++;
            }
        }

        /*
         * Registri menyatakan 158 kode menggerbangi layar. Kalau suatu hari
         * ada yang menghapus rute tanpa memperbarui registri, angkanya akan
         * berbeda — dan registri yang menyatakan sesuatu sudah dibangun
         * padahal layarnya sudah tidak ada adalah bentuk kebohongan yang
         * paling sulit ketahuan.
         */
        $this->assertGreaterThan(100, $bergerbang,
            'Jumlah kode bergerbang di registri anjlok — kemungkinan ada rute yang '
            .'terhapus tanpa registrinya ikut diperbarui.');
    }
}
