<?php

namespace Tests\Feature\Encounter;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Encounter\Models\Registration;
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

class BarcodeTest extends TestCase
{
    use RefreshDatabase;

    private User $petugasDaftar;
    private User $petugasRanap;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->petugasDaftar = User::query()->create([
            'username' => 'uji-barcode-daftar', 'name' => 'Petugas Daftar Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->petugasDaftar->roles()->attach(Role::query()->where('code', 'petugas-daftar')->firstOrFail());

        $this->petugasRanap = User::query()->create([
            'username' => 'uji-barcode-ranap', 'name' => 'Petugas Ranap Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->petugasRanap->roles()->attach(Role::query()->where('code', 'petugas-ranap')->firstOrFail());
    }

    #[Test]
    public function petugas_daftar_dapat_mencetak_barcode_kunjungan_ralan(): void
    {
        $registrasi = $this->daftarkan('ralan');

        $this->actingAs($this->petugasDaftar)
            ->get(route('registrasi.barcode', $registrasi->id))
            ->assertOk()
            ->assertSee($registrasi->registration_number)
            ->assertSee($registrasi->patient_name);
    }

    #[Test]
    public function petugas_daftar_dapat_mencetak_barcode_kunjungan_ranap(): void
    {
        $registrasi = $this->daftarkan('ranap');

        $this->actingAs($this->petugasDaftar)
            ->get(route('registrasi.barcode', $registrasi->id))
            ->assertOk()
            ->assertSee($registrasi->registration_number);
    }

    #[Test]
    public function petugas_ranap_ditolak_mencetak_barcode_kunjungan_ralan(): void
    {
        $registrasi = $this->daftarkan('ralan');

        $this->actingAs($this->petugasRanap)
            ->get(route('registrasi.barcode', $registrasi->id))
            ->assertForbidden();
    }

    #[Test]
    public function petugas_ranap_dapat_mencetak_barcode_kunjungan_ranap(): void
    {
        $registrasi = $this->daftarkan('ranap');

        $this->actingAs($this->petugasRanap)
            ->get(route('registrasi.barcode', $registrasi->id))
            ->assertOk();
    }

    // ------------------------------------------------------------------ bantu

    private function daftarkan(string $careType): Registration
    {
        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Barcode ' . $careType, 'sex' => 'L', 'birth_date' => '1990-01-01',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
            extra: ['care_type' => $careType],
        );
    }
}
