<?php

namespace Tests\Feature\Clinical;

use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Rekam medis hanya boleh dibuka staf klinis.
 *
 * MENGAPA UJI INI ADA — dan ini bukan uji pencegahan biasa, melainkan uji
 * atas lubang yang SUNGGUH PERNAH TERBUKA.
 *
 * Gerbang `can:penilaian_awal_medis_ralan` dipasang dengan
 * `})->middleware(...)` di UJUNG grup rute. Middleware itu tidak pernah
 * berlaku: `group()` sudah mendaftarkan seluruh rutenya lebih dulu, jadi
 * memasangnya sesudah itu tidak menyentuh apa pun. Tidak ada galat, tidak
 * ada peringatan — berkas rutenya terbaca seolah tergerbang, dan rutenya
 * berjalan dengan `web | auth` saja.
 *
 * Selama itu, SETIAP pengguna terautentikasi bisa membuka rekam medis
 * pasien, menulis asesmen, menegakkan dan menghapus diagnosis: kasir,
 * petugas parkir, pustakawan, petugas toko koperasi.
 *
 * Seluruh 2.082 uji lain tetap hijau. Uji per konteks memakai pengguna yang
 * MEMANG punya haknya, jadi tidak satu pun pernah menanyakan apa yang
 * terjadi kalau yang membuka adalah orang yang tidak berhak.
 *
 * Karena itu yang diuji di sini bukan "yang berhak bisa masuk" — melainkan
 * arah sebaliknya, yang jauh lebih mudah rusak tanpa ketahuan.
 */
class ClinicalAccessGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class]);
    }

    private function pengguna(string $peran): User
    {
        $user = User::query()->create([
            'username' => 'uji-'.$peran, 'name' => 'Uji '.$peran,
            'password' => 'password', 'is_active' => true,
        ]);

        $user->roles()->attach(Role::query()->where('code', $peran)->firstOrFail());

        return $user;
    }

    /**
     * Peran non-klinis yang paling sering ada di rumah sakit dan paling
     * jelas tidak berkepentingan atas isi rekam medis.
     *
     * @return list<string>
     */
    public static function peranNonKlinis(): array
    {
        return [
            ['kasir'],
            ['petugas-parkir'],
            ['pustakawan'],
            ['petugas-toko'],
            ['petugas-dapur'],
        ];
    }

    #[Test]
    #[DataProvider('peranNonKlinis')]
    public function peran_non_klinis_ditolak_membuka_rekam_medis(string $peran): void
    {
        $this->actingAs($this->pengguna($peran))
            ->get('/rme')
            ->assertForbidden();
    }

    #[Test]
    public function staf_klinis_tetap_bisa_membuka_rekam_medis(): void
    {
        /*
         * Sisi lain dari uji di atas, dan sama perlunya: gerbang yang
         * diperbaiki dengan cara menutup semua orang bukan perbaikan.
         */
        $this->actingAs($this->pengguna('dokter'))
            ->get('/rme')
            ->assertOk();

        $this->actingAs($this->pengguna('perawat'))
            ->get('/rme')
            ->assertOk();
    }

    #[Test]
    public function seluruh_rute_rekam_medis_tergerbang_bukan_cuma_halaman_daftarnya(): void
    {
        /*
         * Lubangnya dulu menganga di SELURUH grup, bukan cuma di halaman
         * daftar. Menguji satu rute saja akan lolos walau sepuluh rute
         * lainnya terbuka — dan yang paling berbahaya justru bukan
         * halaman daftar, melainkan yang MENULIS: finalkan asesmen,
         * hapus diagnosis.
         */
        $kasir = $this->pengguna('kasir');

        $tertutup = [];

        foreach (Route::getRoutes() as $rute) {
            if (! str_starts_with($rute->uri(), 'rme')) {
                continue;
            }

            $punyaGerbang = false;

            foreach ($rute->gatherMiddleware() as $m) {
                if (is_string($m) && str_starts_with($m, 'can:')) {
                    $punyaGerbang = true;
                    break;
                }
            }

            if (! $punyaGerbang) {
                $tertutup[] = implode('|', $rute->methods()).' '.$rute->uri();
            }
        }

        $this->assertSame([], $tertutup,
            'Rute rekam medis berikut TIDAK punya gerbang can: sama sekali — setiap '
            ."pengguna terautentikasi bisa mengaksesnya:\n  ".implode("\n  ", $tertutup));

        // Dan dibuktikan sekali lagi lewat permintaan sungguhan, bukan cuma
        // dari daftar middleware: daftar bisa benar sementara urutannya salah.
        $this->actingAs($kasir)->get('/rme/kode-diagnosis')->assertForbidden();
    }
}
