<?php

namespace Tests\Feature\Integration;

use App\Modules\Integration\Models\Claim;
use App\Modules\Integration\Models\OutboundMessage;
use App\Modules\Integration\Services\Bpjs\SmartClaimService;
use App\Modules\Integration\Services\IntegrationException;
use App\Modules\Integration\Services\PayerReferenceService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Smart Klaim FHIR BPJS (domain L item N).
 *
 * Yang paling perlu dikunci:
 *
 * 1. SMART KLAIM BUKAN KLAIM KEDUA. Isinya disusun dari klaim yang SUDAH
 *    DIBEKUKAN, bukan dari rekam medis yang dibaca ulang — kalau dibaca
 *    ulang, klaim yang sudah diverifikasi BPJS bisa berubah tanpa ada yang
 *    menyentuhnya.
 * 2. KODE ICD DILEWATKAN APA ADANYA. Berbeda dari SATUSEHAT yang menuntut
 *    sistem kode lain, Smart Klaim memakai ICD-10/ICD-9-CM yang memang
 *    sudah kita simpan; mewajibkan pemetaan akan menahan setiap klaim
 *    tanpa menambah satu pun kebenaran.
 * 3. KODE CBG DIKIRIM SEBAGAIMANA DITERIMA dari grouper, tidak pernah
 *    dihitung sendiri.
 */
class BpjsSmartClaimTest extends TestCase
{
    use RefreshDatabase;

    private SmartClaimService $smart;
    private PayerReferenceService $pemetaan;
    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class]);

        $this->smart = app(SmartClaimService::class);
        $this->pemetaan = app(PayerReferenceService::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-smart-klaim', 'name' => 'Petugas Klaim Uji',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    // ------------------------------------------------------------- pengiriman

    #[Test]
    public function bundle_klaim_terkirim_dan_tercatat_di_buku_kirim(): void
    {
        $klaim = $this->klaimBeku();

        $pesan = $this->smart->send($klaim, $this->petugas->id);

        $this->assertSame(OutboundMessage::STATUS_SENT, $pesan->status);
        $this->assertStringStartsWith('SK-', $pesan->external_reference);
        $this->assertSame('smart-klaim', $pesan->resource_type);
        $this->assertCount(1, $this->smart->transmissions($klaim));
    }

    /**
     * ATURAN PERTAMA: hanya klaim yang isinya sudah dibekukan.
     */
    #[Test]
    public function klaim_yang_masih_draf_belum_punya_isi_beku(): void
    {
        $klaim = $this->klaimBeku(['status' => Claim::DRAF, 'diagnoses' => null]);

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('belum punya isi beku');

        $this->smart->send($klaim);
    }

    #[Test]
    public function klaim_tanpa_diagnosis_ditolak_sebelum_dikirim(): void
    {
        $klaim = $this->klaimBeku(['diagnoses' => []]);

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('tanpa diagnosis');

        $this->smart->send($klaim);
    }

    /** Isi bundle berasal dari klaim beku, bukan dari rekam medis hidup. */
    #[Test]
    public function bundle_disusun_dari_isi_klaim_yang_dibekukan(): void
    {
        $klaim = $this->klaimBeku();

        $bundle = $this->smart->buildBundle($klaim);
        $resource = $bundle['entry'][0]['resource'];

        $this->assertSame('Bundle', $bundle['resourceType']);
        $this->assertSame('Claim', $resource['resourceType']);
        $this->assertCount(2, $resource['diagnosis']);
        $this->assertSame('E11.9', $resource['diagnosis'][0]['diagnosisCodeableConcept']['coding'][0]['code']);
        $this->assertSame('KLM-0001', $resource['identifier'][0]['value']);
        $this->assertEquals(1500000, $resource['total']['value']);
    }

    /** Diagnosis utama ditandai — tarif CBG bersandar padanya. */
    #[Test]
    public function diagnosis_utama_dan_sekunder_dibedakan_di_bundle(): void
    {
        $bundle = $this->smart->buildBundle($this->klaimBeku());
        $diagnosis = $bundle['entry'][0]['resource']['diagnosis'];

        $this->assertSame('principal', $diagnosis[0]['type'][0]['coding'][0]['code']);
        $this->assertSame('secondary', $diagnosis[1]['type'][0]['coding'][0]['code']);
    }

    /**
     * ATURAN KETIGA: kode CBG dikirim sebagaimana diterima grouper.
     */
    #[Test]
    public function kode_cbg_ikut_dikirim_sebagaimana_diterima_grouper(): void
    {
        $bundle = $this->smart->buildBundle($this->klaimBeku());
        $penunjang = collect($bundle['entry'][0]['resource']['supportingInfo']);

        $this->assertTrue($penunjang->contains(fn ($i) => ($i['valueString'] ?? null) === 'A-4-10-I'));
        $this->assertTrue($penunjang->contains(fn ($i) => ($i['valueString'] ?? null) === 'SEP-0001'));
    }

    #[Test]
    public function prosedur_dikirim_dengan_sistem_icd9(): void
    {
        $bundle = $this->smart->buildBundle($this->klaimBeku());
        $prosedur = $bundle['entry'][0]['resource']['procedure'];

        $this->assertCount(1, $prosedur);
        $this->assertSame(
            'http://hl7.org/fhir/sid/icd-9-cm',
            $prosedur[0]['procedureCodeableConcept']['coding'][0]['system']
        );
    }

    #[Test]
    public function klaim_rawat_inap_dikirim_sebagai_institutional(): void
    {
        $bundle = $this->smart->buildBundle($this->klaimBeku(['care_type' => 'ranap']));

        $this->assertSame('institutional', $bundle['entry'][0]['resource']['type']['coding'][0]['code']);
    }

    // ---------------------------------------------------------- pemetaan kode

    /**
     * ATURAN KEDUA: tanpa pemetaan, ICD-10 kita dikirim apa adanya —
     * bukan ditahan seperti pada SATUSEHAT.
     */
    #[Test]
    public function kode_icd_tanpa_pemetaan_tetap_dikirim_apa_adanya(): void
    {
        $bundle = $this->smart->buildBundle($this->klaimBeku());

        $this->assertSame(
            'E11.9',
            $bundle['entry'][0]['resource']['diagnosis'][0]['diagnosisCodeableConcept']['coding'][0]['code']
        );
    }

    /** Pemetaan dipakai hanya untuk pengecualian, dan saat itu ia menang. */
    #[Test]
    public function pemetaan_mengalahkan_kode_bawaan_bila_ada(): void
    {
        $this->pemetaan->seedMappings('bpjs', SmartClaimService::PEMETAAN_PENYAKIT, [
            ['code' => 'E11.9', 'name' => 'Diabetes Melitus Tipe 2'],
        ]);
        $this->pemetaan->map('bpjs', SmartClaimService::PEMETAAN_PENYAKIT, 'E11.9', 'E11.90', 'Versi BPJS', $this->petugas->id);

        $bundle = $this->smart->buildBundle($this->klaimBeku());

        $this->assertSame(
            'E11.90',
            $bundle['entry'][0]['resource']['diagnosis'][0]['diagnosisCodeableConcept']['coding'][0]['code']
        );
    }

    #[Test]
    public function pemetaan_prosedur_juga_berlaku(): void
    {
        $this->pemetaan->seedMappings('bpjs', SmartClaimService::PEMETAAN_PROSEDUR, [
            ['code' => '89.52', 'name' => 'Elektrokardiogram'],
        ]);
        $this->pemetaan->map('bpjs', SmartClaimService::PEMETAAN_PROSEDUR, '89.52', '89.520', 'Versi BPJS', $this->petugas->id);

        $bundle = $this->smart->buildBundle($this->klaimBeku());

        $this->assertSame(
            '89.520',
            $bundle['entry'][0]['resource']['procedure'][0]['procedureCodeableConcept']['coding'][0]['code']
        );
    }

    // ------------------------------------------------------------ pengulangan

    /**
     * Pengiriman ulang atas isi yang sama tidak menggandakan baris buku
     * kirim — kunci idempotennya peristiwa pembekuan klaimnya.
     */
    #[Test]
    public function pengiriman_ulang_tidak_menggandakan_baris_buku_kirim(): void
    {
        $klaim = $this->klaimBeku();

        $this->smart->send($klaim);
        $this->smart->send($klaim);

        $this->assertCount(1, $this->smart->transmissions($klaim));
        $this->assertSame(2, $this->smart->transmissions($klaim)->first()->attempt_count);
    }

    // ------------------------------------------------------------------ bantu

    private function klaimBeku(array $ubah = []): Claim
    {
        return Claim::query()->create(array_merge([
            'claim_number' => 'KLM-0001',
            'claim_type' => Claim::INACBG,
            'registration_id' => 1,
            'registration_number' => '20260101-00001',
            'patient_id' => 1,
            'patient_name' => 'Pasien Klaim Uji',
            'card_number' => '0001234567890',
            'sep_number' => 'SEP-0001',
            'care_type' => 'ralan',
            'admitted_on' => now()->toDateString(),
            'hospital_charge' => 1500000,
            'diagnoses' => [
                ['code' => 'E11.9', 'display' => 'Diabetes Melitus Tipe 2', 'rank' => 'utama'],
                ['code' => 'I10', 'display' => 'Hipertensi Esensial', 'rank' => 'sekunder'],
            ],
            'procedures' => [
                ['code' => '89.52', 'display' => 'Elektrokardiogram'],
            ],
            'cbg_code' => 'A-4-10-I',
            'cbg_description' => 'Diabetes ringan',
            'cbg_tariff' => 1200000,
            'status' => Claim::TERKIRIM,
            'submitted_at' => now(),
        ], $ubah));
    }
}
