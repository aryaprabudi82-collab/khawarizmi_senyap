<?php

namespace Tests\Feature\Integration;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Integration\Models\OutboundMessage;
use App\Modules\Integration\Services\IdentityMappingService;
use App\Modules\Integration\Services\PharmacyContext;
use App\Modules\Integration\Services\Satusehat\CodeMappingService;
use App\Modules\Integration\Services\Satusehat\MedicationSyncService;
use App\Modules\Organization\Models\Practitioner;
use App\Modules\Organization\Models\Unit;
use App\Modules\Pharmacy\Database\Seeders\PharmacySeeder;
use App\Modules\Pharmacy\Models\Drug;
use App\Modules\Pharmacy\Models\Prescription;
use App\Modules\Pharmacy\Models\StockLocation;
use App\Modules\Pharmacy\Services\PrescriptionService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Rantai farmasi SATUSEHAT (domain L item I).
 *
 * Yang paling perlu dikunci:
 *
 * 1. SATU MEDICATION PER OBAT, bukan per baris resep — kalau tidak,
 *    platform nasional mengira ada beberapa obat berbeda dengan kode KFA
 *    yang sama.
 * 2. YANG DIRESEPKAN DAN YANG DISERAHKAN DILAPORKAN TERPISAH. Saat berbeda
 *    (stok kurang), menyamakannya membuat fasilitas berikutnya mengira
 *    pasien punya persediaan lebih banyak daripada kenyataannya.
 * 3. ATURAN PAKAI IKUT DIKIRIM APA ADANYA. Resep tanpa aturan pakai adalah
 *    resep yang menyebut obatnya tanpa menyebut cara memakainya.
 */
class SatusehatMedicationTest extends TestCase
{
    use RefreshDatabase;

    private MedicationSyncService $sinkron;
    private PrescriptionService $resep;
    private CodeMappingService $kode;
    private IdentityMappingService $pemetaan;
    private StockLocation $depo;
    private User $apoteker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PermissionCatalogSeeder::class,
            RoleSeeder::class,
            ReferenceDataSeeder::class,
            PharmacySeeder::class,
        ]);

        $this->sinkron = app(MedicationSyncService::class);
        $this->resep = app(PrescriptionService::class);
        $this->kode = app(CodeMappingService::class);
        $this->pemetaan = app(IdentityMappingService::class);
        $this->depo = StockLocation::query()->where('code', 'DEPO-RJ')->firstOrFail();

        $this->apoteker = User::query()->create([
            'username' => 'uji-satusehat-farmasi', 'name' => 'Apoteker Uji',
            'password' => 'password', 'is_active' => true,
        ]);
        $this->apoteker->roles()->attach(Role::query()->where('code', 'super-admin')->firstOrFail());
    }

    // -------------------------------------------------------- rantai lengkap

    #[Test]
    public function rantai_farmasi_terkirim_lengkap_dan_saling_menunjuk(): void
    {
        $resep = $this->resepDiserahkan();

        $hasil = $this->sinkron->syncPrescription($resep->id);

        $this->assertNull($hasil['gagal_pada']);
        $this->assertCount(2, $hasil['medication']);
        $this->assertCount(2, $hasil['medicationrequest']);
        $this->assertCount(2, $hasil['medicationdispense']);

        $butir = app(PharmacyContext::class)->itemsFor($resep->id)->first();
        $permintaan = $this->muatan('medicationrequest', (int) $butir->item_id);
        $penyerahan = $this->muatan('medicationdispense', (int) $butir->item_id);

        $medicationId = $hasil['medication'][$butir->drug_code];

        $this->assertSame("Medication/{$medicationId}", $permintaan['medicationReference']['reference']);
        $this->assertSame("Medication/{$medicationId}", $penyerahan['medicationReference']['reference']);
        $this->assertSame(
            'MedicationRequest/' . $hasil['medicationrequest'][$butir->drug_name],
            $penyerahan['authorizingPrescription'][0]['reference']
        );
    }

    /**
     * ATURAN PERTAMA: obat yang sama diresepkan pada dua pasien tetap SATU
     * obat. Menyusun Medication baru tiap kali obatnya diresepkan membuat
     * platform nasional mengira ada banyak obat berbeda dengan kode KFA
     * yang sama.
     */
    #[Test]
    public function obat_yang_sama_pada_dua_resep_tetap_satu_medication(): void
    {
        $pertama = $this->resepDiserahkan();
        $kedua = $this->resepDiserahkan();

        $hasilPertama = $this->sinkron->syncPrescription($pertama->id);
        $hasilKedua = $this->sinkron->syncPrescription($kedua->id);

        $this->assertSame($hasilPertama['medication'], $hasilKedua['medication']);

        // Dua obat, bukan empat — meski diresepkan dua kali.
        $this->assertSame(2, OutboundMessage::query()->where('resource_type', 'medication')->count());

        // Resepnya sendiri tetap dua, masing-masing dengan permintaannya.
        $this->assertSame(4, OutboundMessage::query()->where('resource_type', 'medicationrequest')->count());
    }

    /**
     * ATURAN KEDUA: yang diresepkan dan yang diserahkan dilaporkan terpisah.
     */
    #[Test]
    public function jumlah_diresepkan_dan_diserahkan_dilaporkan_terpisah(): void
    {
        $resep = $this->resepDiserahkan();

        $this->sinkron->syncPrescription($resep->id);

        $butir = app(PharmacyContext::class)->itemsFor($resep->id)->first();

        $permintaan = $this->muatan('medicationrequest', (int) $butir->item_id);
        $penyerahan = $this->muatan('medicationdispense', (int) $butir->item_id);

        $this->assertEquals(
            (float) $butir->prescribed_quantity,
            $permintaan['dispenseRequest']['quantity']['value']
        );
        $this->assertEquals(
            (float) $butir->dispensed_quantity,
            $penyerahan['quantity']['value']
        );
    }

    /**
     * ATURAN KETIGA: resep tanpa aturan pakai adalah resep yang menyebut
     * obatnya tanpa menyebut cara memakainya.
     */
    #[Test]
    public function aturan_pakai_dikirim_apa_adanya_sebagai_teks(): void
    {
        $resep = $this->resepDiserahkan(aturan: '3x1 sesudah makan, habiskan');

        $this->sinkron->syncPrescription($resep->id);

        $butir = app(PharmacyContext::class)->itemsFor($resep->id)->first();
        $permintaan = $this->muatan('medicationrequest', (int) $butir->item_id);

        $this->assertSame(
            '3x1 sesudah makan, habiskan',
            $permintaan['dosageInstruction'][0]['text']
        );
    }

    // ------------------------------------------------------------ penyerahan

    /**
     * Menyusun MedicationDispense sebelum obatnya diserahkan berarti
     * melaporkan sesuatu yang belum terjadi.
     */
    #[Test]
    public function obat_yang_belum_diserahkan_tidak_dibuatkan_dispense(): void
    {
        $resep = $this->resepDisetujui();

        $hasil = $this->sinkron->syncPrescription($resep->id);

        $this->assertNull($hasil['gagal_pada']);
        $this->assertCount(2, $hasil['medicationrequest']);
        $this->assertSame([], $hasil['medicationdispense']);
        $this->assertCount(2, $hasil['belum_diserahkan']);
        $this->assertSame(0, OutboundMessage::query()->where('resource_type', 'medicationdispense')->count());
    }

    /** Resep yang sudah diserahkan dilaporkan 'completed', bukan 'active'. */
    #[Test]
    public function status_medicationrequest_mengikuti_status_resepnya(): void
    {
        $diserahkan = $this->resepDiserahkan();
        $this->sinkron->syncPrescription($diserahkan->id);

        $butir = app(PharmacyContext::class)->itemsFor($diserahkan->id)->first();

        $this->assertSame(
            'completed',
            $this->muatan('medicationrequest', (int) $butir->item_id)['status']
        );
    }

    // ---------------------------------------------------------- pemetaan KFA

    /**
     * Menebak kode KFA berarti melaporkan obat lain dengan dosis lain —
     * kesalahan yang bisa mencelakakan orang, bukan sekadar mengotori data.
     */
    #[Test]
    public function obat_tanpa_pemetaan_kfa_tidak_dikirim_dan_dilaporkan(): void
    {
        $resep = $this->resepDiserahkan(petakanObatKedua: false);

        $hasil = $this->sinkron->syncPrescription($resep->id);

        $this->assertNull($hasil['gagal_pada']);
        $this->assertCount(1, $hasil['belum_dipetakan']);
        $this->assertCount(1, $hasil['medication']);
        $this->assertCount(1, $hasil['medicationrequest']);
    }

    #[Test]
    public function tidak_ada_yang_dikirim_kalau_seluruh_obatnya_belum_dipetakan(): void
    {
        $resep = $this->resepDiserahkan(petakanObatPertama: false, petakanObatKedua: false);

        $hasil = $this->sinkron->syncPrescription($resep->id);

        $this->assertSame('pemetaan kode', $hasil['gagal_pada']);
        $this->assertSame(0, OutboundMessage::query()->where('resource_type', 'medicationrequest')->count());
    }

    /**
     * Kolom kfa_code di master obat dipakai sebagai BAHAN AWAL pemetaan,
     * bukan sebagai sumber kode kedua saat mengirim.
     */
    #[Test]
    public function pemetaan_disiapkan_dari_master_obat_berikut_kode_kfa_yang_sudah_ada(): void
    {
        $obat = $this->obat('OBT-002');
        $obat->update(['kfa_code' => '93000123']);

        $hasil = $this->sinkron->seedMappingsFromDrugMaster($this->apoteker->id);

        $this->assertGreaterThan(0, $hasil['disiapkan']);
        $this->assertSame(1, $hasil['terisi_dari_master']);

        $kode = $this->kode->resolve('obat', $obat->code);

        $this->assertSame('93000123', $kode['code']);
        $this->assertSame('kfa', $kode['system']);
    }

    /** Menyiapkan ulang tidak menimpa pemetaan yang sudah dikerjakan orang. */
    #[Test]
    public function penyiapan_ulang_tidak_menimpa_pemetaan_yang_sudah_ada(): void
    {
        $obat = $this->obat('OBT-002');
        $obat->update(['kfa_code' => '93000123']);

        $this->kode->seedFrom('obat', [['code' => $obat->code, 'name' => $obat->name]]);
        $this->kode->map('obat', $obat->code, 'kfa', '99999999', 'Dipetakan manual', $this->apoteker->id);

        $this->sinkron->seedMappingsFromDrugMaster($this->apoteker->id);

        $this->assertSame('99999999', $this->kode->resolve('obat', $obat->code)['code']);
    }

    // ---------------------------------------------------------- telaah farmasi

    /**
     * Telaah yang tidak menemukan apa-apa dan telaah yang belum dikerjakan
     * adalah dua pernyataan yang berbeda.
     */
    #[Test]
    public function telaah_yang_belum_dikerjakan_tidak_dikirim(): void
    {
        $resep = $this->resepBaru();
        $this->resep->addItem($resep, $this->obat('OBT-002')->id, 10, '3x1');
        $this->siapkan($resep);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('belum ditelaah apoteker');

        $this->sinkron->syncReview($resep->id);
    }

    #[Test]
    public function telaah_tanpa_temuan_dikirim_sebagai_tidak_ada_temuan(): void
    {
        $resep = $this->resepDisetujui();

        $this->sinkron->syncReview($resep->id);

        $telaah = app(PharmacyContext::class)->reviewFor($resep->id);
        $muatan = $this->muatan('pharmacyreview', (int) $telaah->review_id);

        $this->assertSame('QuestionnaireResponse', $muatan['resourceType']);
        $this->assertSame('Disetujui', $muatan['item'][0]['answer'][0]['valueString']);
        $this->assertSame('Tidak ada temuan', $muatan['item'][1]['answer'][0]['valueString']);
        $this->assertSame('Apoteker Uji', $muatan['author']['display']);
    }

    /** Telaah yang MENOLAK resep justru yang paling berarti secara klinis. */
    #[Test]
    public function telaah_yang_menolak_resep_tetap_dikirim(): void
    {
        $resep = $this->resepBaru();
        $this->resep->addItem($resep, $this->obat('OBT-002')->id, 10, '3x1');
        $this->siapkan($resep);
        $this->resep->submit($resep->refresh());
        $this->resep->review($resep->refresh(), 'ditolak', 'Dosis melebihi batas harian.', $this->apoteker);

        $this->sinkron->syncReview($resep->id);

        $telaah = app(PharmacyContext::class)->reviewFor($resep->id);
        $muatan = $this->muatan('pharmacyreview', (int) $telaah->review_id);

        $this->assertSame('Ditolak', $muatan['item'][0]['answer'][0]['valueString']);
        $this->assertSame('Dosis melebihi batas harian.', $muatan['item'][2]['answer'][0]['valueString']);
    }

    /** Resep yang ditolak apoteker dilaporkan 'cancelled', bukan disembunyikan. */
    #[Test]
    public function resep_yang_ditolak_apoteker_dilaporkan_cancelled(): void
    {
        $resep = $this->resepBaru();
        $this->resep->addItem($resep, $this->obat('OBT-002')->id, 10, '3x1');
        $this->siapkan($resep);
        $this->resep->submit($resep->refresh());
        $this->resep->review($resep->refresh(), 'ditolak', 'Duplikasi terapi.', $this->apoteker);
        $this->petakanObat(['OBT-002']);

        $this->sinkron->syncPrescription($resep->id);

        $butir = app(PharmacyContext::class)->itemsFor($resep->id)->first();

        $this->assertSame(
            'cancelled',
            $this->muatan('medicationrequest', (int) $butir->item_id)['status']
        );
    }

    /**
     * Telaah adalah pernyataan APOTEKER atas resep, bukan pernyataan dokter —
     * jadi pemetaan dokter tidak disyaratkan untuk mengirimnya. Menuntutnya
     * akan menahan telaah karena alasan yang tidak ada hubungannya.
     */
    #[Test]
    public function telaah_tetap_bisa_dikirim_meski_dokternya_belum_dipetakan(): void
    {
        $resep = $this->resepBaru();
        $this->resep->addItem($resep, $this->obat('OBT-002')->id, 10, '3x1');
        $this->siapkan($resep, petakanDokter: false);
        $this->resep->submit($resep->refresh());
        $this->resep->review($resep->refresh(), 'disetujui', null, $this->apoteker);

        $id = $this->sinkron->syncReview($resep->id);

        $this->assertNotEmpty($id);
    }

    // ------------------------------------------------------------- prasyarat

    #[Test]
    public function dokter_penulis_resep_yang_belum_dipetakan_menghentikan_pengiriman(): void
    {
        $resep = $this->resepDiserahkan(petakanDokter: false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Dokter penulis resep belum dipetakan');

        $this->sinkron->syncPrescription($resep->id);
    }

    #[Test]
    public function pengiriman_ulang_memakai_id_yang_sama(): void
    {
        $resep = $this->resepDiserahkan();

        $pertama = $this->sinkron->syncPrescription($resep->id);
        $kedua = $this->sinkron->syncPrescription($resep->id);

        $this->assertSame($pertama['medication'], $kedua['medication']);
        $this->assertSame($pertama['medicationrequest'], $kedua['medicationrequest']);
        $this->assertSame($pertama['medicationdispense'], $kedua['medicationdispense']);
    }

    // ------------------------------------------------------------------ bantu

    /** @return array<string, mixed> */
    private function muatan(string $resourceType, int $sourceId): array
    {
        return OutboundMessage::query()
            ->where('resource_type', $resourceType)
            ->where('source_id', $sourceId)
            ->firstOrFail()
            ->request_payload;
    }

    private function obat(string $kode): Drug
    {
        return Drug::query()->where('code', $kode)->firstOrFail();
    }

    private function resepBaru(): Prescription
    {
        return $this->resep->create($this->daftarkan()->id, $this->apoteker);
    }

    /** Resep dua obat yang sudah disetujui apoteker tapi belum diserahkan. */
    private function resepDisetujui(string $aturan = '3x1 sesudah makan'): Prescription
    {
        $resep = $this->resepBaru();

        $this->resep->addItem($resep, $this->obat('OBT-002')->id, 30, $aturan);
        $this->resep->addItem($resep, $this->obat('OBT-003')->id, 10, '1x1 malam');

        $this->siapkan($resep);
        $this->petakanObat(['OBT-002', 'OBT-003']);

        $this->resep->submit($resep->refresh());

        return $this->resep->review($resep->refresh(), 'disetujui', null, $this->apoteker);
    }

    private function resepDiserahkan(
        string $aturan = '3x1 sesudah makan',
        bool $petakanObatPertama = true,
        bool $petakanObatKedua = true,
        bool $petakanDokter = true,
    ): Prescription {
        $resep = $this->resepBaru();

        $this->resep->addItem($resep, $this->obat('OBT-002')->id, 30, $aturan);
        $this->resep->addItem($resep, $this->obat('OBT-003')->id, 10, '1x1 malam');

        $this->siapkan($resep, $petakanDokter);

        $this->petakanObat(array_filter([
            $petakanObatPertama ? 'OBT-002' : null,
            $petakanObatKedua ? 'OBT-003' : null,
        ]));

        $this->resep->submit($resep->refresh());
        $this->resep->review($resep->refresh(), 'disetujui', null, $this->apoteker);

        return $this->resep->dispense($resep->refresh(), $this->depo->id, $this->apoteker);
    }

    /** @param  array<int, string>  $kode */
    private function petakanObat(array $kode): void
    {
        if ($kode === []) {
            return;
        }

        $obat = Drug::query()->whereIn('code', $kode)->get();

        $this->kode->seedFrom('obat', $obat->map(fn ($o) => ['code' => $o->code, 'name' => $o->name]));

        foreach ($obat as $i => $o) {
            $this->kode->map('obat', $o->code, 'kfa', '9300' . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT), $o->name);
        }
    }

    /** Memetakan pasien, kunjungan, dan dokter resep ini ke ID SATUSEHAT. */
    private function siapkan(Prescription $resep, bool $petakanDokter = true): void
    {
        $this->pemetaan->setManually(
            'satusehat', 'patient', 'identity', $resep->patient_id,
            'P-' . $resep->patient_id, $this->apoteker->id
        );

        $this->pemetaan->setManually(
            'satusehat', 'encounter', 'encounter', $resep->registration_id,
            'E-' . $resep->registration_id, $this->apoteker->id
        );

        if ($petakanDokter && $resep->prescriber_id !== null) {
            $this->pemetaan->setManually(
                'satusehat', 'practitioner', 'organization', $resep->prescriber_id,
                'PR-' . $resep->prescriber_id, $this->apoteker->id
            );
        }
    }

    private function daftarkan(): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Farmasi SATUSEHAT ' . $urut, 'sex' => 'L', 'birth_date' => '1980-07-07',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
            practitionerId: Practitioner::query()->where('is_active', true)->value('id'),
        );
    }
}
