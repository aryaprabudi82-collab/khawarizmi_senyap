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
use Illuminate\Support\Facades\DB;
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

    #[Test]
    public function persetujuan_pemeriksaan_hiv_bisa_dicatat_dan_mencetak_catatan_kerahasiaan(): void
    {
        $persetujuan = $this->consents->issue([
            'consent_type' => 'pemeriksaan-hiv', 'patient_name' => 'Andi Wijaya',
            'procedure_description' => 'Pemeriksaan HIV sebelum tindakan operasi elektif.', 'decision' => 'setuju',
        ], $this->dokter->id);

        $this->assertContains($persetujuan->consent_type, PatientConsent::TYPES);

        $this->actingAs($this->dokter)
            ->get(route('correspondence.persetujuan.cetak', $persetujuan))
            ->assertOk()
            ->assertSee('Persetujuan Pemeriksaan HIV')
            ->assertSee('rahasia');
    }

    #[Test]
    public function seluruh_jenis_consent_dan_certificate_yang_diperlebar_bisa_dicatat(): void
    {
        /*
         * Uji ini SEBELUMNYA menerbitkan setiap jenis dengan data minimal
         * yang sama. Properti itu sengaja dihapus pada domain P item A-C:
         * beberapa jenis kini memang menuntut lebih — persetujuan memilih
         * DPJP menuntut dokternya, penolakan anjuran medis menuntut akibat
         * yang dijelaskan, surat "bebas X" menuntut hasil pemeriksaannya,
         * dan surat sakit pihak kedua menuntut identitas orang yang
         * membutuhkannya. Yang tetap dijaga di sini adalah maksud aslinya:
         * SETIAP jenis yang terdaftar harus bisa diterbitkan dan dicetak,
         * supaya peta judul di blade tidak pernah kehilangan kunci.
         */
        foreach (PatientConsent::TYPES as $jenis) {
            $persetujuan = $this->consents->issue([
                'consent_type' => $jenis, 'patient_name' => 'Pasien Uji',
                'procedure_description' => 'Uraian untuk jenis '.$jenis,
                'decision' => 'setuju',
            ] + $this->syaratTambahanConsent($jenis), $this->dokter->id);

            $this->assertSame($jenis, $persetujuan->consent_type);

            // Menjamin $judul di cetak.blade.php punya entri untuk setiap TYPES —
            // key yang hilang berarti "Undefined array key" saat dicetak.
            $this->actingAs($this->dokter)
                ->get(route('correspondence.persetujuan.cetak', $persetujuan))
                ->assertOk();
        }

        foreach (MedicalCertificate::TYPES as $jenis) {
            $surat = $this->certificates->issue([
                'certificate_type' => $jenis, 'patient_name' => 'Pasien Uji', 'purpose' => 'uji coba',
                'content' => 'Isi untuk jenis '.$jenis, 'valid_from' => now()->toDateString(),
            ] + $this->syaratTambahanCertificate($jenis), $this->dokter->id);

            $this->assertSame($jenis, $surat->certificate_type);

            $this->actingAs($this->dokter)
                ->get(route('correspondence.keterangan.cetak', $surat))
                ->assertOk();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function syaratTambahanConsent(string $jenis): array
    {
        return match ($jenis) {
            'memilih-dpjp' => ['chosen_practitioner_name' => 'dr. Andi'],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function syaratTambahanCertificate(string $jenis): array
    {
        if (in_array($jenis, MedicalCertificate::JENIS_BERTEMUAN, true)) {
            return [
                'examination_result' => 'Hasil pemeriksaan untuk jenis '.$jenis,
                'is_clear' => true,
            ];
        }

        if ($jenis === MedicalCertificate::JENIS_SAKIT_PIHAK_KEDUA) {
            return ['third_party_name' => 'Siti Aminah', 'third_party_relationship' => 'istri'];
        }

        if ($jenis === MedicalCertificate::JENIS_RAWAT_INAP) {
            return ['registration_id' => $this->buatAdmisiUji()];
        }

        return [];
    }

    /** Surat keterangan rawat inap menyalin periodenya dari admisi. */
    private function buatAdmisiUji(): int
    {
        $registrationId = 710001;

        DB::table('inpatient.admissions')->insert([
            'admission_number' => 'ADM-DOK-UJI',
            'registration_id' => $registrationId,
            'patient_id' => 810001,
            'patient_mrn' => 'RM-DOK-UJI',
            'patient_name' => 'Pasien Uji',
            'bed_id' => $this->bedUji(),
            'admitted_at' => now()->subDays(4),
            'discharged_at' => now()->subDay(),
            'status' => 'pulang',
            'discharge_status' => 'sembuh',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $registrationId;
    }

    /** Bed dibuat lewat kueri langsung — correspondence tidak boleh mengimpor model konteks lain. */
    private function bedUji(): int
    {
        $roomId = DB::table('inpatient.rooms')->insertGetId([
            'room_number' => 'UJI-DOK', 'room_class' => 'kelas-3',
            'daily_rate' => 250000, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('inpatient.beds')->insertGetId([
            'room_id' => $roomId, 'bed_number' => 'A', 'status' => 'tersedia',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
