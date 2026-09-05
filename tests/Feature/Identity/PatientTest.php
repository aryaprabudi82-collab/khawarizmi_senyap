<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Models\Patient;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Audit ulang konteks identity (2026-10): 4 kode tercatat context=identity
 * di katalog (suku_bangsa, bahasa_pasien, perusahaan_pasien,
 * klasifikasi_pasien_ranap) ternyata tanpa kolom/layar sama sekali —
 * ditambahkan sebagai kolom teks bebas di identity.patients, konsisten
 * dengan precedent modul ini sendiri (religion/marital_status/education/
 * occupation juga kolom teks bebas, bukan tabel master terpisah). Modul
 * ini sebelumnya tidak punya test sama sekali — file ini yang pertama.
 */
class PatientTest extends TestCase
{
    use RefreshDatabase;

    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class]);

        $this->petugas = User::query()->create([
            'username' => 'uji-pasien', 'name' => 'Petugas Pendaftaran Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->petugas->roles()->attach(Role::query()->where('code', 'petugas-daftar')->firstOrFail());
    }

    #[Test]
    public function pasien_baru_menyimpan_suku_bahasa_instansi_dan_klasifikasi_ranap(): void
    {
        $this->actingAs($this->petugas)->post(route('pasien.store'), [
            'name' => 'Budi Santoso',
            'sex' => 'L',
            'ethnicity' => 'Jawa',
            'language' => 'Indonesia',
            'employer' => 'PT Sumber Makmur',
            'inpatient_classification' => 'Prioritas',
        ])->assertRedirect();

        $pasien = Patient::query()->where('name', 'Budi Santoso')->firstOrFail();

        $this->assertSame('Jawa', $pasien->ethnicity);
        $this->assertSame('Indonesia', $pasien->language);
        $this->assertSame('PT Sumber Makmur', $pasien->employer);
        $this->assertSame('Prioritas', $pasien->inpatient_classification);
    }

    #[Test]
    public function keempat_kolom_boleh_dikosongkan(): void
    {
        $this->actingAs($this->petugas)->post(route('pasien.store'), [
            'name' => 'Siti Aminah',
            'sex' => 'P',
        ])->assertRedirect();

        $pasien = Patient::query()->where('name', 'Siti Aminah')->firstOrFail();

        $this->assertNull($pasien->ethnicity);
        $this->assertNull($pasien->language);
        $this->assertNull($pasien->employer);
        $this->assertNull($pasien->inpatient_classification);
    }

    #[Test]
    public function layar_pasien_hanya_untuk_yang_berhak(): void
    {
        $this->actingAs($this->petugas)->get(route('pasien.index'))->assertOk();
        $this->actingAs($this->petugas)->get(route('pasien.create'))->assertOk();

        $petugasLain = User::query()->create([
            'username' => 'uji-tanpa-akses-pasien', 'name' => 'Petugas Lain Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $petugasLain->roles()->attach(Role::query()->where('code', 'petugas-cssd')->firstOrFail());

        $this->actingAs($petugasLain)->get(route('pasien.index'))->assertForbidden();
    }
}
