<?php

namespace Tests\Feature\Encounter;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Encounter\Models\IgdTriage;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\IgdService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IgdTest extends TestCase
{
    use RefreshDatabase;

    private IgdService $igd;
    private User $perawat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->igd = app(IgdService::class);

        $this->perawat = User::query()->create([
            'username' => 'uji-perawat-igd', 'name' => 'Perawat IGD Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->perawat->roles()->attach(Role::query()->where('code', 'perawat')->firstOrFail());
    }

    #[Test]
    public function registrasi_igd_memakai_unit_igd_dan_tanpa_kuota(): void
    {
        $pasien = app(PatientRegistry::class)->register(['name' => 'Pasien IGD', 'sex' => 'L', 'birth_date' => '1990-01-01']);
        $umum = Payer::query()->where('code', 'UMUM')->value('id');

        $registrasi = $this->igd->register($pasien->id, $umum);

        $this->assertSame('igd', $registrasi->care_type);
        $this->assertSame('Instalasi Gawat Darurat', $registrasi->unit_name);
    }

    #[Test]
    public function triase_tercatat_dan_bisa_diulang(): void
    {
        $pasien = app(PatientRegistry::class)->register(['name' => 'Pasien IGD', 'sex' => 'L', 'birth_date' => '1990-01-01']);
        $registrasi = $this->igd->register($pasien->id, Payer::query()->where('code', 'UMUM')->value('id'));

        $this->igd->triage($registrasi, 'kuning', 'Nyeri dada', $this->perawat);
        $this->assertSame('kuning', IgdTriage::query()->where('registration_id', $registrasi->id)->value('triage_level'));

        // Retriase — kondisi memburuk, dinaikkan ke merah.
        $this->igd->triage($registrasi, 'merah', 'Nyeri dada memberat, sesak napas', $this->perawat);

        $this->assertSame(1, IgdTriage::query()->where('registration_id', $registrasi->id)->count());
        $this->assertSame('merah', IgdTriage::query()->where('registration_id', $registrasi->id)->value('triage_level'));
    }

    #[Test]
    public function pasien_igd_diurutkan_berdasar_keparahan_bukan_waktu_datang(): void
    {
        $umum = Payer::query()->where('code', 'UMUM')->value('id');

        $pertama = app(PatientRegistry::class)->register(['name' => 'Datang Duluan', 'sex' => 'L', 'birth_date' => '1990-01-01']);
        $regPertama = $this->igd->register($pertama->id, $umum);
        $this->igd->triage($regPertama, 'hijau', 'Luka ringan', $this->perawat);

        $kedua = app(PatientRegistry::class)->register(['name' => 'Datang Belakangan', 'sex' => 'L', 'birth_date' => '1990-01-01']);
        $regKedua = $this->igd->register($kedua->id, $umum);
        $this->igd->triage($regKedua, 'merah', 'Henti napas', $this->perawat);

        $this->actingAs($this->perawat)
            ->get(route('igd.index'))
            ->assertOk()
            ->assertSeeInOrder(['Datang Belakangan', 'Datang Duluan']);
    }

    #[Test]
    public function layar_igd_hanya_untuk_dokter_dan_perawat_bukan_petugas_daftar(): void
    {
        $this->actingAs($this->perawat)->get(route('igd.index'))->assertOk();

        $dokter = User::query()->create([
            'username' => 'uji-dokter-igd', 'name' => 'Dokter IGD Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());
        $this->actingAs($dokter)->get(route('igd.index'))->assertOk();

        $petugasDaftar = User::query()->create([
            'username' => 'uji-loket-igd', 'name' => 'Petugas Loket Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $petugasDaftar->roles()->attach(Role::query()->where('code', 'petugas-daftar')->firstOrFail());
        $this->actingAs($petugasDaftar)->get(route('igd.index'))->assertForbidden();
    }

    #[Test]
    public function pasien_bisa_didaftarkan_ke_igd_lewat_http(): void
    {
        $pasien = app(PatientRegistry::class)->register(['name' => 'Pasien IGD HTTP', 'sex' => 'L', 'birth_date' => '1990-01-01']);
        $umum = Payer::query()->where('code', 'UMUM')->firstOrFail();

        $this->actingAs($this->perawat)
            ->post(route('igd.daftar'), ['pasien_id' => $pasien->id, 'penjamin_id' => $umum->id])
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $this->assertDatabaseHas('encounter.registrations', [
            'patient_id' => $pasien->id, 'care_type' => 'igd',
        ]);
    }
}
