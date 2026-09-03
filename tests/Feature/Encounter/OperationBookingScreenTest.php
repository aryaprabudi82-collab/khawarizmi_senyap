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

class OperationBookingScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $petugasDaftar;
    private User $perawat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->petugasDaftar = User::query()->create([
            'username' => 'uji-booking-op-http', 'name' => 'Petugas Daftar Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->petugasDaftar->roles()->attach(Role::query()->where('code', 'petugas-daftar')->firstOrFail());

        $this->perawat = User::query()->create([
            'username' => 'uji-perawat-booking-op', 'name' => 'Perawat Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->perawat->roles()->attach(Role::query()->where('code', 'perawat')->firstOrFail());
    }

    #[Test]
    public function pencarian_kunjungan_menampilkan_hasil_yang_cocok(): void
    {
        $registrasi = $this->daftarkan('Warsiti Operasi');

        $this->actingAs($this->petugasDaftar)
            ->get(route('booking-operasi.index', ['q' => $registrasi->registration_number]))
            ->assertOk()
            ->assertSee('Warsiti Operasi');
    }

    #[Test]
    public function petugas_daftar_dapat_menjadwalkan_operasi_lewat_layar(): void
    {
        $registrasi = $this->daftarkan('Untung Operasi');

        $this->actingAs($this->petugasDaftar)
            ->post(route('booking-operasi.simpan'), [
                'registration_id' => $registrasi->id,
                'procedure_name' => 'Appendektomi',
                'scheduled_at' => now()->addDays(3)->format('Y-m-d\TH:i'),
            ])
            ->assertRedirect(route('booking-operasi.index'))
            ->assertSessionHas('sukses');

        $this->assertDatabaseHas('encounter.operation_bookings', [
            'registration_id' => $registrasi->id, 'procedure_name' => 'Appendektomi',
        ]);
    }

    #[Test]
    public function perawat_ditolak_mengakses_layar_jadwal_operasi(): void
    {
        $this->actingAs($this->perawat)
            ->get(route('booking-operasi.index'))
            ->assertForbidden();
    }

    // ------------------------------------------------------------------ bantu

    private function daftarkan(string $nama): Registration
    {
        $pasien = app(PatientRegistry::class)->register([
            'name' => $nama, 'sex' => 'L', 'birth_date' => '1990-01-01',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
        );
    }
}
