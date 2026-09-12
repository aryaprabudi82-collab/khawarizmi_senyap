<?php

namespace Tests\Feature\Platform;

use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Setiap peran harus mendarat di layar yang boleh dibukanya.
 *
 * MENGAPA UJI INI ADA. Beranda dulu SELALU mengalihkan ke pendaftaran
 * pasien, siapa pun yang masuk. Akibatnya setiap peran yang tidak berhak
 * mendaftarkan pasien — manajemen, apoteker, petugas dapur, petugas aset,
 * pustakawan, petugas toko — menabrak 403 pada detik pertama sesudah
 * memasukkan kata sandi yang BENAR, tanpa pernah melihat satu pun layar
 * yang menjadi haknya.
 *
 * SELURUH 2.126 UJI LAIN TETAP HIJAU. Semuanya memanggil route() tujuannya
 * langsung; tidak satu pun pernah masuk lalu berhenti di beranda seperti
 * yang dilakukan manusia. Ditemukan hanya karena layarnya dibuka lewat
 * peramban, satu peran demi satu peran.
 *
 * Uji ini menempuh jalan yang sama: untuk TIAP peran yang diseed, buka
 * beranda dan pastikan yang keluar bukan 403.
 */
class LandingAfterLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);
    }

    /**
     * INTI BERKAS INI, dan sengaja menyapu SELURUH peran: yang rusak dulu
     * bukan satu peran melainkan semua yang bukan petugas loket.
     */
    #[Test]
    public function tiap_peran_mendarat_di_layar_yang_boleh_dibukanya(): void
    {
        $gagal = [];

        foreach (Role::query()->orderBy('code')->get() as $peran) {
            $pengguna = User::query()->create([
                'username' => 'uji-darat-'.$peran->code,
                'name' => 'Uji '.$peran->name,
                'password' => 'password',
                'is_active' => true,
            ]);
            $pengguna->roles()->attach($peran);

            $jawaban = $this->actingAs($pengguna)->get('/');

            if ($jawaban->status() === 403) {
                $gagal[] = $peran->code.' -> 403';

                continue;
            }

            // Pengalihan pun harus bermuara di halaman yang bisa dibuka,
            // bukan di 403 satu langkah kemudian.
            if ($jawaban->isRedirect()) {
                $tujuan = $this->actingAs($pengguna)->get($jawaban->headers->get('Location'));

                if ($tujuan->status() === 403) {
                    $gagal[] = $peran->code.' -> dialihkan ke halaman terlarang';
                }
            }
        }

        $this->assertSame([], $gagal,
            count($gagal)." peran menabrak halaman terlarang tepat setelah masuk:\n  "
            .implode("\n  ", $gagal)
            ."\n\nPengguna yang kata sandinya BENAR lalu melihat \"Forbidden\" tidak punya "
            .'cara membedakannya dari akun bermasalah. Tambahkan layar pembukanya di '
            .'LandingResolver, atau pastikan perannya memang punya sesuatu untuk dibuka.');
    }

    /**
     * Peran tanpa hak sama sekali TIDAK menabrak galat — ia melihat
     * penjelasan. "Forbidden" pada akun yang kata sandinya benar adalah
     * jawaban yang menyesatkan.
     */
    #[Test]
    public function pengguna_tanpa_hak_melihat_penjelasan_bukan_galat(): void
    {
        $pengguna = User::query()->create([
            'username' => 'uji-tanpa-peran', 'name' => 'Tanpa Peran',
            'password' => 'password', 'is_active' => true,
        ]);

        $this->actingAs($pengguna)->get('/')
            ->assertOk()
            ->assertSee('Belum ada layar yang bisa dibuka')
            ->assertSee('belum punya peran sama sekali');
    }

    /** Kasir mendarat di kasir, bukan di piutang. */
    #[Test]
    public function kasir_mendarat_di_layar_kasir(): void
    {
        $this->assertMendarat('kasir', 'tagihan');
    }

    /** Manajemen mendarat di Pusat Keuangan, bukan formulir pemetaan akun. */
    #[Test]
    public function manajemen_mendarat_di_pusat_keuangan(): void
    {
        $this->assertMendarat('manajemen', 'keuangan');
    }

    #[Test]
    public function apoteker_mendarat_di_antrean_resep(): void
    {
        $this->assertMendarat('apoteker', 'resep');
    }

    /**
     * Petugas lab dan radiologi mendarat di antrean UNITNYA MASING-MASING.
     * Mengantar keduanya ke kategori yang sama berarti salah satunya
     * membuka antrean milik unit lain setiap pagi.
     */
    #[Test]
    public function petugas_penunjang_mendarat_di_antrean_unitnya(): void
    {
        $this->assertMendarat('petugas-lab', 'order/lab');
        $this->assertMendarat('petugas-radiologi', 'order/radiologi');
        $this->assertMendarat('petugas-lab-pa', 'order/pa');
    }

    private function assertMendarat(string $kodePeran, string $jalurDiharapkan): void
    {
        $peran = Role::query()->where('code', $kodePeran)->firstOrFail();

        $pengguna = User::query()->create([
            'username' => 'uji-darat2-'.$kodePeran, 'name' => 'Uji '.$kodePeran,
            'password' => 'password', 'is_active' => true,
        ]);
        $pengguna->roles()->attach($peran);

        $this->actingAs($pengguna)->get('/')
            ->assertRedirect()
            ->assertHeader('Location', url($jalurDiharapkan));
    }
}
