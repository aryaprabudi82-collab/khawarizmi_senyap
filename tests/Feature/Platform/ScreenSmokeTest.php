<?php

namespace Tests\Feature\Platform;

use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Penyapu layar: setiap layar daftar dibuka sekali, dalam keadaan KOSONG.
 *
 * MENGAPA UJI INI ADA. Uji fitur menguji aturan — apa yang boleh dan
 * tidak boleh terjadi pada data. Yang TIDAK diujinya adalah apakah
 * halamannya bisa dibuka sama sekali. Sebuah salah ketik pada berkas
 * blade, relasi yang lupa dimuat, atau variabel yang lupa dikirim
 * controller tidak akan menggagalkan satu pun uji aturan — layarnya cuma
 * gagal dibuka saat ada orang mencobanya.
 *
 * KEADAAN KOSONG DIPILIH DENGAN SENGAJA. Justru di situ layar paling
 * sering pecah: `@forelse` yang benar tapi ringkasan di atasnya
 * mengasumsikan ada baris, `->first()->nama` pada koleksi kosong,
 * pembagian dengan nol pada rekap. Dan keadaan kosong itulah yang dilihat
 * RSP UI pada hari pertama — jadi kalau ada satu keadaan yang wajib
 * bekerja, ini dia.
 *
 * Dijalankan sebagai super-admin supaya seluruh gerbang terbuka: yang
 * diuji di sini kesehatan halamannya, bukan kewenangannya — kewenangan
 * sudah punya ujinya sendiri di tiap konteks.
 */
class ScreenSmokeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Rute yang memang bukan halaman, atau butuh keadaan yang tidak masuk
     * akal dibuat di sini. Didaftar dengan alasannya, bukan didiamkan —
     * daftar pengecualian tanpa alasan akan tumbuh sampai ujinya tidak
     * menguji apa-apa lagi.
     */
    private const DIKECUALIKAN = [
        'login' => 'Halaman tamu, bukan layar aplikasi.',
        'logout' => 'Aksi, bukan halaman.',
    ];

    #[Test]
    public function setiap_layar_daftar_bisa_dibuka_dalam_keadaan_kosong(): void
    {
        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $admin = User::query()->create([
            'username' => 'uji-smoke', 'name' => 'Penyapu Layar',
            'password' => 'password', 'is_active' => true,
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'super-admin')->firstOrFail());

        $gagal = [];
        $diuji = 0;

        foreach (Route::getRoutes() as $rute) {
            if (! in_array('GET', $rute->methods(), true)) {
                continue;
            }

            $uri = $rute->uri();

            // Rute berparameter butuh baris yang ada; keadaan kosong justru
            // yang hendak diuji di sini, jadi mereka dilewati.
            if (str_contains($uri, '{')) {
                continue;
            }

            $nama = $rute->getName();

            if ($nama !== null && array_key_exists($nama, self::DIKECUALIKAN)) {
                continue;
            }

            $diuji++;

            $respons = $this->actingAs($admin)->get('/'.ltrim($uri, '/'));
            $status = $respons->getStatusCode();

            // 200 sehat; 302 pengalihan yang sah (mis. ke layar bawaan).
            if (! in_array($status, [200, 302], true)) {
                $gagal[] = $uri.' ['.$nama.'] => '.$status;
            }
        }

        $this->assertGreaterThan(50, $diuji, 'Penyapu ini harus menyentuh seluruh layar, bukan segelintir.');

        $this->assertSame([], $gagal, "Layar berikut gagal dibuka dalam keadaan kosong:\n".implode("\n", $gagal));
    }
}
