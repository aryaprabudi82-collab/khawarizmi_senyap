<?php

namespace Tests\Feature\Platform;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Order\Database\Seeders\TestCatalogSeeder;
use App\Modules\Order\Models\TestCatalog;
use App\Modules\Order\Services\OrderService;
use App\Modules\Organization\Models\Unit;
use App\Modules\Pharmacy\Database\Seeders\PharmacySeeder;
use App\Modules\Pharmacy\Models\Drug;
use App\Modules\Pharmacy\Services\PrescriptionService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Jumlah kueri layar daftar tidak boleh tumbuh mengikuti jumlah barisnya.
 *
 * MENGAPA UJI INI ADA. N+1 adalah cacat yang paling mudah lolos dari
 * seluruh uji lain: hasilnya BENAR, layarnya terbuka, dan di basis data uji
 * yang berisi tiga baris ia menambah tiga kueri yang selesai dalam
 * sepersekian milidetik. Yang berubah cuma jumlah perjalanan ke basis data,
 * dan tidak ada yang menghitungnya.
 *
 * Pada RSP UI, layar resep menampilkan lima puluh baris per halaman, dan
 * dibuka terus-menerus sepanjang jam layanan. `$r->items()->count()` di
 * dalam perulangan blade berarti lima puluh satu perjalanan untuk satu
 * halaman — dan sejak aplikasi dan basis data berdiri di MESIN TERPISAH,
 * tiap perjalanan menanggung latensi jaringan, bukan cuma waktu kueri.
 *
 * CARANYA: bandingkan jumlah kueri pada dua jumlah baris yang berbeda.
 * Menyatakan ambang mutlak ("tidak boleh lebih dari 12 kueri") akan rapuh —
 * ia patah setiap kali ada penyaring baru yang sah ditambahkan, lalu orang
 * menaikkan angkanya sampai ujinya tidak menjaga apa pun. Yang diuji di
 * sini sifatnya: jumlah kueri harus TETAP saat barisnya bertambah.
 */
class ListScreenQueryCountTest extends TestCase
{
    use RefreshDatabase;

    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PermissionCatalogSeeder::class,
            RoleSeeder::class,
            ReferenceDataSeeder::class,
            PharmacySeeder::class,
            TestCatalogSeeder::class,
        ]);

        $this->petugas = User::query()->create([
            'username' => 'uji-kueri', 'name' => 'Petugas Uji',
            'password' => 'password', 'is_active' => true,
        ]);
        $this->petugas->roles()->attach(Role::query()->where('code', 'super-admin')->firstOrFail());
    }

    /**
     * Menghitung kueri yang dijalankan saat sebuah layar dibuka.
     *
     * DIPANASKAN DULU. Permintaan pertama dalam satu uji membawa biaya
     * sekali-jalan yang tidak ada hubungannya dengan jumlah baris: baris
     * sesi dibuat, katalog izin dibaca dan disimpan di memori. Tanpa
     * pemanasan, pengukuran pertama selalu lebih tinggi daripada yang kedua
     * dan ujinya gagal ke arah yang keliru — melaporkan perbaikan sebagai
     * kemunduran.
     */
    private function jumlahKueri(string $url): int
    {
        $this->actingAs($this->petugas)->get($url);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($this->petugas)->get($url)->assertOk();

        $jumlah = count(DB::getQueryLog());

        DB::disableQueryLog();

        return $jumlah;
    }

    #[Test]
    public function layar_resep_tidak_menambah_kueri_per_baris(): void
    {
        $this->buatResep(2);
        $duaBaris = $this->jumlahKueri('/resep');

        $this->buatResep(4);   // total jadi 6
        $enamBaris = $this->jumlahKueri('/resep');

        $this->assertSame($duaBaris, $enamBaris,
            "Jumlah kueri layar resep tumbuh dari {$duaBaris} jadi {$enamBaris} saat barisnya "
            .'bertambah dari 2 ke 6 — itu N+1. Pada halaman berisi lima puluh baris, '
            ."tiap baris jadi satu perjalanan tersendiri ke basis data.\n"
            .'Penyebab yang paling sering: memanggil relasi di dalam perulangan blade '
            .'(`$r->items()->count()`) alih-alih `withCount()` di controller.');
    }

    #[Test]
    public function layar_order_penunjang_tidak_menambah_kueri_per_baris(): void
    {
        $this->buatOrder(2);
        $duaBaris = $this->jumlahKueri('/order/lab');

        $this->buatOrder(4);
        $enamBaris = $this->jumlahKueri('/order/lab');

        $this->assertSame($duaBaris, $enamBaris,
            "Jumlah kueri layar order tumbuh dari {$duaBaris} jadi {$enamBaris} — itu N+1.");
    }

    #[Test]
    public function layar_antrean_hanya_memuat_yang_belum_selesai(): void
    {
        $selesai = $this->daftarkan();
        $selesai->update(['status' => 'selesai']);

        $menunggu = $this->daftarkan();

        $respons = $this->actingAs($this->petugas)->get('/antrean/display');

        /*
         * Perbaikan kebenaran, bukan cuma skala: layar antrean yang
         * menampilkan pasien yang sudah pulang bukan layar antrean, ia
         * daftar kunjungan — dan nomor yang sudah selesai mendorong nomor
         * yang sedang menunggu keluar layar.
         *
         * Pada 2.000 pasien sehari, menampilkan "seluruh kunjungan hari ini"
         * juga berarti memuat dua ribu baris setiap dua puluh detik untuk
         * menampilkan puluhan.
         */
        $respons->assertOk()
            ->assertSee(str_pad((string) $menunggu->queue_number, 3, '0', STR_PAD_LEFT))
            ->assertDontSee(str_pad((string) $selesai->queue_number, 3, '0', STR_PAD_LEFT));
    }

    // ------------------------------------------------------------ pembantu

    private function daftarkan()
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Kueri '.$urut, 'sex' => 'L', 'birth_date' => '1990-01-01',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
        );
    }

    private function buatResep(int $jumlah): void
    {
        $obat = Drug::query()->where('code', 'OBT-002')->value('id');

        for ($i = 0; $i < $jumlah; $i++) {
            $registrasi = $this->daftarkan();
            $resep = app(PrescriptionService::class)->create($registrasi->id);
            app(PrescriptionService::class)->addItem($resep, $obat, 5, '3x1');
        }
    }

    private function buatOrder(int $jumlah): void
    {
        $tes = TestCatalog::query()->where('code', 'LAB-GDS')->value('id');

        for ($i = 0; $i < $jumlah; $i++) {
            $registrasi = $this->daftarkan();
            $order = app(OrderService::class)->create($registrasi->id, 'lab');
            app(OrderService::class)->addItem($order, $tes);
        }
    }
}
