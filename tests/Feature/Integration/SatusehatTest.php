<?php

namespace Tests\Feature\Integration;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Clinical\Models\Assessment;
use App\Modules\Clinical\Services\ClinicalRecordService;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Models\Patient;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Integration\Models\OutboundMessage;
use App\Modules\Integration\Services\IdentityMappingService;
use App\Modules\Integration\Services\Satusehat\ConditionSyncService;
use App\Modules\Integration\Services\Satusehat\EncounterSyncService;
use App\Modules\Integration\Services\Satusehat\PatientSyncService;
use App\Modules\Organization\Models\Practitioner;
use App\Modules\Organization\Models\Unit;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class SatusehatTest extends TestCase
{
    use RefreshDatabase;

    private PatientSyncService $patients;
    private EncounterSyncService $encounters;
    private ConditionSyncService $conditions;
    private IdentityMappingService $mappings;
    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->patients = app(PatientSyncService::class);
        $this->encounters = app(EncounterSyncService::class);
        $this->conditions = app(ConditionSyncService::class);
        $this->mappings = app(IdentityMappingService::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-satusehat', 'name' => 'Petugas SATUSEHAT Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->petugas->roles()->attach(Role::query()->where('code', 'petugas-integrasi')->firstOrFail());
    }

    #[Test]
    public function pasien_tersinkron_mencatat_pemetaan_dan_ledger_terkirim(): void
    {
        $pasien = $this->buatPasien();

        $pesan = $this->patients->sync($pasien->id);

        $this->assertSame(OutboundMessage::STATUS_SENT, $pesan->status);
        $this->assertNotNull($pesan->external_reference);

        $peta = $this->mappings->find('satusehat', 'patient', 'identity', $pasien->id);
        $this->assertNotNull($peta);
        $this->assertSame($pesan->external_reference, $peta->external_id);
    }

    #[Test]
    public function sinkron_ulang_pasien_memakai_id_eksternal_yang_sama_bukan_membuat_baru(): void
    {
        $pasien = $this->buatPasien();

        $pertama = $this->patients->sync($pasien->id);
        $kedua = $this->patients->sync($pasien->id);

        $this->assertSame($pertama->external_reference, $kedua->external_reference);
    }

    #[Test]
    public function kunjungan_tidak_bisa_disinkron_sebelum_pasiennya(): void
    {
        $registrasi = $this->daftarkan();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Sinkronkan pasien terlebih dahulu');

        $this->encounters->sync($registrasi->id);
    }

    #[Test]
    public function kunjungan_tersinkron_setelah_pasiennya(): void
    {
        $registrasi = $this->daftarkan();
        $this->patients->sync($registrasi->patient_id);

        $pesan = $this->encounters->sync($registrasi->id);

        $this->assertSame(OutboundMessage::STATUS_SENT, $pesan->status);
        $this->assertNotNull($this->mappings->find('satusehat', 'encounter', 'encounter', $registrasi->id));
    }

    #[Test]
    public function kunjungan_menyertakan_lokasi_dan_praktisi_kalau_sudah_dipetakan(): void
    {
        $unit = Unit::query()->where('code', 'POL-UMUM')->firstOrFail();
        $praktisi = Practitioner::query()->where('code', 'DR001')->firstOrFail();
        $this->mappings->setManually('satusehat', 'location', 'organization', $unit->id, 'loc-001', $this->petugas->id);
        $this->mappings->setManually('satusehat', 'practitioner', 'organization', $praktisi->id, 'prac-001', $this->petugas->id);

        $registrasi = $this->daftarkan($praktisi->id);
        $this->patients->sync($registrasi->patient_id);
        $pesan = $this->encounters->sync($registrasi->id);

        $this->assertSame('Location/loc-001', $pesan->request_payload['location'][0]['location']['reference']);
        $this->assertSame('Practitioner/prac-001', $pesan->request_payload['participant'][0]['individual']['reference']);
    }

    #[Test]
    public function diagnosis_tidak_bisa_disinkron_tanpa_diagnosis_utama(): void
    {
        $registrasi = $this->daftarkan();
        $this->patients->sync($registrasi->patient_id);
        $this->encounters->sync($registrasi->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('belum punya diagnosis utama');

        $this->conditions->syncPrimary($registrasi->id);
    }

    #[Test]
    public function diagnosis_tersinkron_setelah_pasien_dan_kunjungan(): void
    {
        $registrasi = $this->daftarkan();
        $this->catatDiagnosisUtama($registrasi);

        $this->patients->sync($registrasi->patient_id);
        $this->encounters->sync($registrasi->id);

        $pesan = $this->conditions->syncPrimary($registrasi->id);

        $this->assertSame(OutboundMessage::STATUS_SENT, $pesan->status);
        $this->assertSame('A09', $pesan->request_payload['code']['coding'][0]['code']);
    }

    #[Test]
    public function layar_satusehat_hanya_untuk_petugas_integrasi(): void
    {
        $this->actingAs($this->petugas)->get(route('integrasi.satusehat.index'))->assertOk();

        $dokter = User::query()->create([
            'username' => 'uji-dokter-satusehat', 'name' => 'Dokter Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)->get(route('integrasi.satusehat.index'))->assertForbidden();
    }

    // ------------------------------------------------------------------ bantu

    private function buatPasien(int $urut = 0): Patient
    {
        static $counter = 0;
        $counter++;

        return app(PatientRegistry::class)->register([
            'name' => 'Pasien SATUSEHAT Uji ' . $counter,
            'sex' => 'L',
            'nik' => str_pad((string) (3200000000000000 + $counter), 16, '0'),
            'birth_date' => '1990-01-01',
            'address' => 'Jl. Contoh No. 1',
            'city_name' => 'Depok',
            'province_name' => 'Jawa Barat',
        ]);
    }

    private function daftarkan(?int $practitionerId = null): Registration
    {
        $pasien = $this->buatPasien();

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
            practitionerId: $practitionerId,
        );
    }

    private function catatDiagnosisUtama(Registration $registrasi): void
    {
        $clinical = app(ClinicalRecordService::class);
        $assessment = $clinical->openAssessment($registrasi->id, Assessment::KIND_SOAP);
        $clinical->addDiagnosis($assessment, 'A09', 'Diare dan gastroenteritis', 'utama', 'kerja');
    }
}
