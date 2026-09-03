<?php

namespace Tests\Feature\Finance;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Finance\Database\Seeders\ChartOfAccountsSeeder;
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

class DepositScreenTest extends TestCase
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
            ChartOfAccountsSeeder::class,
        ]);

        $this->petugasKeuangan = User::query()->create([
            'username' => 'uji-deposit-http', 'name' => 'Petugas Keuangan Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->petugasKeuangan->roles()->attach(Role::query()->where('code', 'petugas-keuangan')->firstOrFail());
    }

    #[Test]
    public function pencarian_kunjungan_menampilkan_hasil_yang_cocok(): void
    {
        $registrasi = $this->daftarkan('Warsiti Deposit');

        $this->actingAs($this->petugasKeuangan)
            ->get(route('deposit.index', ['q' => $registrasi->registration_number]))
            ->assertOk()
            ->assertSee('Warsiti Deposit')
            ->assertSee($registrasi->registration_number);
    }

    #[Test]
    public function petugas_keuangan_dapat_mencatat_deposit_lewat_layar(): void
    {
        $registrasi = $this->daftarkan('Untung Deposit');

        $this->actingAs($this->petugasKeuangan)
            ->post(route('deposit.simpan'), [
                'registration_id' => $registrasi->id,
                'amount' => 300000,
                'note' => 'Uang muka ranap',
            ])
            ->assertRedirect(route('deposit.index'))
            ->assertSessionHas('sukses');

        $this->assertDatabaseHas('finance.deposits', [
            'registration_id' => $registrasi->id, 'amount' => '300000.00',
        ]);
    }

    #[Test]
    public function petugas_daftar_ditolak_mengakses_layar_deposit(): void
    {
        $petugasDaftar = User::query()->create([
            'username' => 'uji-daftar-deposit', 'name' => 'Petugas Daftar', 'password' => 'password', 'is_active' => true,
        ]);
        $petugasDaftar->roles()->attach(Role::query()->where('code', 'petugas-daftar')->firstOrFail());

        $this->actingAs($petugasDaftar)
            ->get(route('deposit.index'))
            ->assertForbidden();
    }

    #[Test]
    public function dokter_ditolak_mengakses_layar_deposit(): void
    {
        $dokter = User::query()->create([
            'username' => 'uji-dokter-deposit', 'name' => 'Dokter Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)
            ->get(route('deposit.index'))
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
