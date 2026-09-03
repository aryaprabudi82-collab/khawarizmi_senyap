<?php

namespace Tests\Feature\Encounter;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Organization\Models\Unit;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class KfrProgramRequestScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $dokter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->dokter = User::query()->create([
            'username' => 'uji-kfr-http', 'name' => 'Dokter Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());
    }

    #[Test]
    public function dokter_dapat_mengajukan_permintaan_program_kfr_lewat_layar(): void
    {
        $pasien = app(PatientRegistry::class)->register(['name' => 'Pasien KFR Http', 'sex' => 'L', 'birth_date' => '1990-01-01']);
        $registrasi = app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
        );

        $this->actingAs($this->dokter)
            ->post(route('program-kfr.simpan'), [
                'registration_id' => $registrasi->id,
                'program_name' => 'Fisioterapi Pasca-Stroke',
                'reason' => 'Kelemahan anggota gerak kanan.',
            ])
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $this->assertDatabaseHas('encounter.kfr_program_requests', [
            'registration_id' => $registrasi->id, 'program_name' => 'Fisioterapi Pasca-Stroke',
        ]);
    }

    #[Test]
    public function perawat_ditolak_mengakses_layar_program_kfr(): void
    {
        $perawat = User::query()->create([
            'username' => 'uji-perawat-kfr', 'name' => 'Perawat Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $perawat->roles()->attach(Role::query()->where('code', 'perawat')->firstOrFail());

        $this->actingAs($perawat)
            ->get(route('program-kfr.index'))
            ->assertForbidden();
    }

    #[Test]
    public function petugas_daftar_ditolak_mengakses_layar_program_kfr(): void
    {
        $petugasDaftar = User::query()->create([
            'username' => 'uji-daftar-kfr', 'name' => 'Petugas Daftar', 'password' => 'password', 'is_active' => true,
        ]);
        $petugasDaftar->roles()->attach(Role::query()->where('code', 'petugas-daftar')->firstOrFail());

        $this->actingAs($petugasDaftar)
            ->get(route('program-kfr.index'))
            ->assertForbidden();
    }
}
