<?php

namespace Tests\Feature\Finance;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Finance\Models\InpatientCostEstimate;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Inpatient\Services\RoomService;
use App\Modules\Organization\Models\Unit;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CostEstimateScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $petugasKeuangan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PermissionCatalogSeeder::class,
            RoleSeeder::class,
            ReferenceDataSeeder::class,
        ]);

        app(RoomService::class)->createRoom(['room_number' => '201', 'room_class' => 'kelas-2', 'daily_rate' => 200000]);

        $this->petugasKeuangan = User::query()->create([
            'username' => 'uji-estimasi-http', 'name' => 'Petugas Keuangan Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->petugasKeuangan->roles()->attach(Role::query()->where('code', 'petugas-keuangan')->firstOrFail());
    }

    #[Test]
    public function pencarian_hanya_menampilkan_kunjungan_rawat_inap(): void
    {
        $ranap = $this->daftarkan('Ranap Estimasi', 'ranap');
        $ralan = $this->daftarkan('Ralan Estimasi', 'ralan');

        $this->actingAs($this->petugasKeuangan)
            ->get(route('estimasi-ranap.index', ['q' => 'Estimasi']))
            ->assertOk()
            ->assertSee('Ranap Estimasi')
            ->assertDontSee('Ralan Estimasi');
    }

    #[Test]
    public function petugas_keuangan_dapat_membuat_dan_mencetak_estimasi(): void
    {
        $registrasi = $this->daftarkan('Rina Estimasi', 'ranap');

        $this->actingAs($this->petugasKeuangan)
            ->post(route('estimasi-ranap.simpan'), [
                'registration_id' => $registrasi->id,
                'room_class' => 'kelas-2',
                'estimated_days' => 4,
                'other_charges' => 100000,
            ])
            ->assertRedirect();

        $estimasi = InpatientCostEstimate::query()->where('registration_id', $registrasi->id)->firstOrFail();
        $this->assertSame('900000.00', $estimasi->total_estimate); // 4x200000 + 100000

        $this->actingAs($this->petugasKeuangan)
            ->get(route('estimasi-ranap.cetak', $estimasi))
            ->assertOk()
            ->assertSee('Rina Estimasi')
            ->assertSee($estimasi->estimate_number);
    }

    #[Test]
    public function dokter_ditolak_mengakses_layar_estimasi(): void
    {
        $dokter = User::query()->create([
            'username' => 'uji-dokter-estimasi', 'name' => 'Dokter Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)
            ->get(route('estimasi-ranap.index'))
            ->assertForbidden();
    }

    // ------------------------------------------------------------------ bantu

    private function daftarkan(string $nama, string $careType): Registration
    {
        $pasien = app(PatientRegistry::class)->register([
            'name' => $nama, 'sex' => 'L', 'birth_date' => '1990-01-01',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
            extra: ['care_type' => $careType],
        );
    }
}
