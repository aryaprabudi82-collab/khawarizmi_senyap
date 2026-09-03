<?php

namespace Tests\Feature\Encounter;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Encounter\Models\OutgoingReferral;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationException;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Encounter\Services\ReferralService;
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

class ReferralTest extends TestCase
{
    use RefreshDatabase;

    private ReferralService $referrals;
    private User $dokter;
    private Registration $registrasi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->referrals = app(ReferralService::class);

        $this->dokter = User::query()->create([
            'username' => 'uji-dokter-rujukan', 'name' => 'Dokter Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $pasien = app(PatientRegistry::class)->register(['name' => 'Pasien Rujukan', 'sex' => 'L', 'birth_date' => '1990-01-01']);
        $this->registrasi = app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
        );
    }

    #[Test]
    public function rujukan_keluar_tercatat_berformat_rjk_tahun_urut(): void
    {
        $rujukan = $this->referrals->issue($this->registrasi, [
            'destination_facility_name' => 'RSUP Rujukan',
            'reason' => 'Butuh penanganan spesialis jantung.',
        ], $this->dokter);

        $this->assertMatchesRegularExpression('/^RJK-\d{4}-\d{5}$/', $rujukan->referral_number);
        $this->assertSame(OutgoingReferral::STATUS_AKTIF, $rujukan->status);
        $this->assertSame($this->registrasi->patient_name, $rujukan->patient_name);
    }

    #[Test]
    public function rujukan_yang_dibatalkan_tidak_bisa_dibatalkan_ulang(): void
    {
        $rujukan = $this->referrals->issue($this->registrasi, [
            'destination_facility_name' => 'RSUP Rujukan', 'reason' => 'Uji.',
        ], $this->dokter);

        $this->referrals->cancel($rujukan);

        $this->expectException(RegistrationException::class);
        $this->referrals->cancel($rujukan);
    }

    #[Test]
    public function rujukan_bisa_dicetak(): void
    {
        $rujukan = $this->referrals->issue($this->registrasi, [
            'destination_facility_name' => 'RSUP Rujukan', 'reason' => 'Butuh penanganan spesialis.',
            'diagnosis' => 'Suspek gagal jantung',
        ], $this->dokter);

        $this->actingAs($this->dokter)
            ->get(route('rujukan-keluar.cetak', $rujukan))
            ->assertOk()
            ->assertSee('RSUP Rujukan')
            ->assertSee('Suspek gagal jantung')
            ->assertSee($rujukan->referral_number);
    }

    #[Test]
    public function rujukan_keluar_bisa_diterbitkan_lewat_http(): void
    {
        $this->actingAs($this->dokter)
            ->post(route('rujukan-keluar.simpan'), [
                'registration_id' => $this->registrasi->id,
                'destination_facility_name' => 'RSUP Rujukan',
                'reason' => 'Butuh penanganan spesialis.',
            ])
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $this->assertDatabaseHas('encounter.outgoing_referrals', [
            'registration_id' => $this->registrasi->id, 'destination_facility_name' => 'RSUP Rujukan',
        ]);
    }

    #[Test]
    public function layar_rujukan_keluar_hanya_untuk_dokter(): void
    {
        $this->actingAs($this->dokter)->get(route('rujukan-keluar.index'))->assertOk();

        $petugasDaftar = User::query()->create([
            'username' => 'uji-loket-rujukan', 'name' => 'Petugas Loket Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $petugasDaftar->roles()->attach(Role::query()->where('code', 'petugas-daftar')->firstOrFail());

        $this->actingAs($petugasDaftar)->get(route('rujukan-keluar.index'))->assertForbidden();

        $perawat = User::query()->create([
            'username' => 'uji-perawat-rujukan', 'name' => 'Perawat Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $perawat->roles()->attach(Role::query()->where('code', 'perawat')->firstOrFail());

        $this->actingAs($perawat)->get(route('rujukan-keluar.index'))->assertForbidden();
    }
}
