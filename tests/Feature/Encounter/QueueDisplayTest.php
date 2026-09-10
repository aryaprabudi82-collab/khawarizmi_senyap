<?php

namespace Tests\Feature\Encounter;

use App\Modules\Encounter\Http\Controllers\QueueDisplayController;
use App\Modules\Pharmacy\Http\Controllers\PharmacyQueueDisplayController;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Layar antrean poliklinik & apotek (Khanza `display` dan `display_apotek`,
 * domain U).
 *
 * TIDAK ADA TABEL BARU untuk kedua kode ini, dan itu temuannya: nomor
 * antrean sudah ada di encounter.registrations dan status resep sudah ada di
 * pharmacy.prescriptions sejak konteks masing-masing dibangun. Yang belum
 * ada cuma layarnya. Membuat tabel antrean terpisah justru akan melahirkan
 * dua sumber kebenaran tentang siapa yang sedang dipanggil.
 *
 * Yang dikunci di sini adalah keputusan yang paling mudah dilanggar
 * belakangan: NAMA PASIEN DISAMARKAN.
 */
class QueueDisplayTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function nama_pasien_disamarkan_di_layar_publik(): void
    {
        /*
         * Layar ini menghadap ruang tunggu — siapa pun yang lewat bisa
         * membacanya, termasuk tetangga, atasan, atau mantan pasangan
         * seseorang. Nama lengkap di sebelah nama poliklinik memberitahu
         * seisi ruangan bahwa orang ini sedang berobat, dan ke poli apa.
         * Untuk poli jiwa, kulit dan kelamin, atau VCT, itu bukan
         * ketidaknyamanan — itu pembukaan rahasia medis.
         */
        $this->assertSame('Siti R. D.', QueueDisplayController::samarkan('Siti Rahmawati Dewi'));
        $this->assertSame('Budi', QueueDisplayController::samarkan('Budi'));
        $this->assertSame('Ahmad F.', QueueDisplayController::samarkan('  Ahmad   Firdausi  '));

        // Kata pertama tetap utuh supaya pasien mengenali dirinya sendiri;
        // cukup bagi yang menunggu, tidak cukup bagi yang cuma lewat.
        $this->assertStringStartsWith('Siti', QueueDisplayController::samarkan('Siti Rahmawati Dewi'));
        $this->assertStringNotContainsString('Rahmawati', QueueDisplayController::samarkan('Siti Rahmawati Dewi'));

        $this->assertSame('—', QueueDisplayController::samarkan(null));
        $this->assertSame('—', QueueDisplayController::samarkan('   '));
    }

    #[Test]
    public function apotek_menyamarkan_dengan_aturan_yang_sama(): void
    {
        // Disalin, bukan dipinjam dari konteks encounter: sepuluh baris
        // fungsi murni lebih murah daripada ketergantungan lintas konteks
        // yang dibuat cuma untuk memformat teks. Ujinya di sini memastikan
        // kedua salinan tidak menyimpang.
        foreach (['Siti Rahmawati Dewi', 'Budi', 'Ahmad Firdausi'] as $nama) {
            $this->assertSame(
                QueueDisplayController::samarkan($nama),
                PharmacyQueueDisplayController::samarkan($nama),
                'Dua salinan penyamaran nama menyimpang untuk "'.$nama.'".'
            );
        }
    }

    #[Test]
    public function layar_antrean_terbuka_dalam_keadaan_kosong(): void
    {
        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class]);

        $loket = $this->pengguna('uji-loket', 'petugas-daftar');
        $apoteker = $this->pengguna('uji-apoteker', 'apoteker');

        /*
         * Keadaan kosong dipilih dengan sengaja: layar antrean hari pertama
         * memang kosong, dan justru di situ halaman paling sering pecah.
         */
        $this->actingAs($loket)->get(route('antrean.display'))
            ->assertOk()
            ->assertSee('Belum ada antrean hari ini.');

        $this->actingAs($apoteker)->get(route('apotek.antrean.display'))
            ->assertOk()
            ->assertSee('Siap Diambil');
    }

    #[Test]
    public function gerbang_layar_apotek_terpisah_dari_gerbang_mengubah_resep(): void
    {
        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class]);

        $loket = $this->pengguna('uji-loket', 'petugas-daftar');

        /*
         * Kios di ruang tunggu tidak boleh memegang kapabilitas yang bisa
         * mengubah resep, dan sebaliknya petugas loket tidak perlu layar
         * antrean apotek. Gerbangnya karena itu dua, bukan dilebur ke
         * `resep_obat`.
         */
        $this->actingAs($loket)->get(route('apotek.antrean.display'))->assertForbidden();
    }

    private function pengguna(string $username, string $peran): User
    {
        $user = User::query()->create([
            'username' => $username, 'name' => 'Uji '.$peran,
            'password' => 'password', 'is_active' => true,
        ]);

        $user->roles()->attach(Role::query()->where('code', $peran)->firstOrFail());

        return $user;
    }
}
