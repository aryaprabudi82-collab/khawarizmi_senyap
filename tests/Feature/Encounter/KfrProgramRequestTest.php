<?php

namespace Tests\Feature\Encounter;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Encounter\Models\KfrProgramRequest;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\KfrProgramRequestService;
use App\Modules\Encounter\Services\RegistrationException;
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

class KfrProgramRequestTest extends TestCase
{
    use RefreshDatabase;

    private KfrProgramRequestService $requests;
    private User $dokter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->requests = app(KfrProgramRequestService::class);

        $this->dokter = User::query()->create([
            'username' => 'uji-kfr', 'name' => 'Dokter Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());
    }

    #[Test]
    public function permintaan_tercatat_berformat_kfr_tahun_urut(): void
    {
        $registrasi = $this->daftarkan();

        $permintaan = $this->requests->issue($registrasi, [
            'program_name' => 'Fisioterapi Pasca-Stroke',
            'reason' => 'Kelemahan anggota gerak kanan pasca-stroke iskemik.',
        ], $this->dokter);

        $this->assertStringStartsWith('KFR-' . now()->format('Y'), $permintaan->request_number);
        $this->assertSame($registrasi->patient_id, $permintaan->patient_id);
        $this->assertSame(KfrProgramRequest::STATUS_DIMINTA, $permintaan->status);
        $this->assertSame($this->dokter->id, $permintaan->requested_by);
    }

    #[Test]
    public function permintaan_yang_sudah_dibatalkan_tidak_bisa_dibatalkan_ulang(): void
    {
        $registrasi = $this->daftarkan();
        $permintaan = $this->requests->issue($registrasi, [
            'program_name' => 'Terapi Wicara', 'reason' => 'Gangguan bicara pasca-cedera.',
        ], $this->dokter);
        $this->requests->cancel($permintaan);

        $this->expectException(RegistrationException::class);
        $this->expectExceptionMessage('sudah dibatalkan');

        $this->requests->cancel($permintaan->refresh());
    }

    // ------------------------------------------------------------------ bantu

    private function daftarkan(): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien KFR ' . $urut, 'sex' => 'L', 'birth_date' => '1990-01-01',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
        );
    }
}
