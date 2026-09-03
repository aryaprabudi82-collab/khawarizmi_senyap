<?php

namespace Tests\Feature\Correspondence;

use App\Modules\Correspondence\Models\MedicalCertificate;
use App\Modules\Correspondence\Models\PatientConsent;
use App\Modules\Correspondence\Services\CertificateService;
use App\Modules\Correspondence\Services\ConsentService;
use App\Modules\Correspondence\Services\CorrespondenceException;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ClinicalDocumentTest extends TestCase
{
    use RefreshDatabase;

    private ConsentService $consents;
    private CertificateService $certificates;
    private User $dokter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->consents = app(ConsentService::class);
        $this->certificates = app(CertificateService::class);

        $this->dokter = User::query()->create([
            'username' => 'uji-dokter-dokumen', 'name' => 'Dokter Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());
    }

    #[Test]
    public function persetujuan_tercatat_berformat_pst_tahun_urut(): void
    {
        $persetujuan = $this->consents->issue([
            'consent_type' => 'tindakan', 'patient_name' => 'Budi Santoso',
            'procedure_description' => 'Insisi dan drainase abses', 'decision' => 'setuju',
        ], $this->dokter->id);

        $this->assertMatchesRegularExpression('/^PST-\d{4}-\d{5}$/', $persetujuan->consent_number);
        $this->assertSame(PatientConsent::STATUS_AKTIF, $persetujuan->status);
    }

    #[Test]
    public function persetujuan_yang_dibatalkan_tidak_bisa_dibatalkan_ulang(): void
    {
        $persetujuan = $this->consents->cancel($this->consents->issue([
            'consent_type' => 'umum', 'patient_name' => 'Budi Santoso',
            'procedure_description' => 'Persetujuan umum rawat jalan', 'decision' => 'setuju',
        ], $this->dokter->id));

        $this->expectException(CorrespondenceException::class);

        $this->consents->cancel($persetujuan);
    }

    #[Test]
    public function surat_keterangan_tercatat_berformat_skt_tahun_urut(): void
    {
        $surat = $this->certificates->issue([
            'certificate_type' => 'sehat', 'patient_name' => 'Budi Santoso', 'purpose' => 'untuk keperluan kerja',
            'content' => 'Pasien dalam keadaan sehat jasmani dan rohani.', 'valid_from' => now()->toDateString(),
        ], $this->dokter->id);

        $this->assertMatchesRegularExpression('/^SKT-\d{4}-\d{5}$/', $surat->certificate_number);
        $this->assertSame(MedicalCertificate::STATUS_DITERBITKAN, $surat->status);
    }

    #[Test]
    public function persetujuan_bisa_dicetak(): void
    {
        $persetujuan = $this->consents->issue([
            'consent_type' => 'tindakan', 'patient_name' => 'Budi Santoso',
            'procedure_description' => 'Jahit luka robek', 'decision' => 'setuju', 'witness_name' => 'Siti Aminah',
        ], $this->dokter->id);

        $this->actingAs($this->dokter)
            ->get(route('correspondence.persetujuan.cetak', $persetujuan))
            ->assertOk()
            ->assertSee('Budi Santoso')
            ->assertSee('Siti Aminah')
            ->assertSee($persetujuan->consent_number);
    }

    #[Test]
    public function layar_dokumen_klinis_hanya_untuk_dokter_bukan_petugas_tu(): void
    {
        $this->actingAs($this->dokter)->get(route('correspondence.persetujuan.index'))->assertOk();
        $this->actingAs($this->dokter)->get(route('correspondence.keterangan.index'))->assertOk();

        $petugasTu = User::query()->create([
            'username' => 'uji-tu-dokumen', 'name' => 'Petugas TU Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $petugasTu->roles()->attach(Role::query()->where('code', 'petugas-tu')->firstOrFail());

        $this->actingAs($petugasTu)->get(route('correspondence.persetujuan.index'))->assertForbidden();
    }
}
