<?php

namespace Tests\Feature\Integration;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Clinical\Models\Assessment;
use App\Modules\Clinical\Services\ClinicalRecordService;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Inpatient\Models\Bed;
use App\Modules\Inpatient\Models\DietOrder;
use App\Modules\Inpatient\Models\Room;
use App\Modules\Inpatient\Services\AdmissionService;
use App\Modules\Inpatient\Services\DietOrderService;
use App\Modules\Integration\Models\OutboundMessage;
use App\Modules\Integration\Services\IdentityMappingService;
use App\Modules\Integration\Services\Satusehat\ClinicalNoteSyncService;
use App\Modules\Organization\Models\Practitioner;
use App\Modules\Organization\Models\Unit;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Catatan klinis & gizi SATUSEHAT (domain L item O).
 *
 * Yang paling perlu dikunci:
 *
 * 1. ASESMEN DRAF TIDAK DIKIRIM. Ia masih bisa berubah, dan fasilitas lain
 *    tidak punya cara membedakan pendapat setengah jadi dari yang final.
 * 2. BAGIAN YANG KOSONG TIDAK DIISI SEADANYA. ClinicalImpression tanpa
 *    penilaian membuat fasilitas lain mengira dokter sudah menilai dan
 *    tidak menemukan apa-apa.
 * 3. DIET YANG DIHENTIKAN TETAP DIKIRIM, sebagai 'revoked' — penghentian
 *    diet adalah keputusan klinis, bukan ketiadaan data.
 */
class SatusehatClinicalNoteTest extends TestCase
{
    use RefreshDatabase;

    private ClinicalNoteSyncService $sinkron;
    private ClinicalRecordService $klinis;
    private IdentityMappingService $pemetaan;
    private User $dokter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->sinkron = app(ClinicalNoteSyncService::class);
        $this->klinis = app(ClinicalRecordService::class);
        $this->pemetaan = app(IdentityMappingService::class);

        $this->dokter = User::query()->create([
            'username' => 'uji-catatan-klinis', 'name' => 'dr. Catatan Uji',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    // ------------------------------------------------------ ClinicalImpression

    #[Test]
    public function penilaian_klinis_terkirim_berikut_penunjang_soap_lainnya(): void
    {
        $asesmen = $this->asesmenFinal();

        $pesan = $this->sinkron->syncClinicalImpression($asesmen->id);

        $this->assertSame(OutboundMessage::STATUS_SENT, $pesan->status);

        $muatan = $pesan->request_payload;

        $this->assertSame('ClinicalImpression', $muatan['resourceType']);
        $this->assertSame('completed', $muatan['status']);
        $this->assertStringContainsString('Diabetes tidak terkontrol', $muatan['description']);

        // Keluhan dan temuan ikut sebagai penunjang, bukan sebagai isi utama.
        $catatan = collect($muatan['note'])->pluck('text')->implode(' | ');
        $this->assertStringContainsString('Keluhan utama', $catatan);
        $this->assertStringContainsString('Objektif', $catatan);
    }

    /**
     * ATURAN PERTAMA: asesmen draf tidak dikirim.
     */
    #[Test]
    public function asesmen_yang_masih_draf_tidak_dikirim(): void
    {
        $asesmen = $this->asesmenFinal(finalkan: false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('masih draf');

        $this->sinkron->syncClinicalImpression($asesmen->id);
    }

    #[Test]
    public function asesmen_yang_tidak_ada_dibedakan_dari_yang_masih_draf(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak ditemukan');

        $this->sinkron->syncClinicalImpression(99999);
    }

    /**
     * ATURAN KEDUA: bagian kosong tidak diisi seadanya.
     */
    #[Test]
    public function asesmen_tanpa_penilaian_tidak_menghasilkan_clinicalimpression(): void
    {
        $asesmen = $this->asesmenFinal(isi: [
            'chief_complaint' => 'Kontrol rutin.',
            'plan' => 'Lanjutkan terapi, kontrol 1 bulan.',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak memuat penilaian klinis');

        $this->sinkron->syncClinicalImpression($asesmen->id);
    }

    // -------------------------------------------------------------- CarePlan

    #[Test]
    public function rencana_tindak_lanjut_terkirim_sebagai_careplan(): void
    {
        $asesmen = $this->asesmenFinal();

        $pesan = $this->sinkron->syncCarePlan($asesmen->id);
        $muatan = $pesan->request_payload;

        $this->assertSame('CarePlan', $muatan['resourceType']);
        $this->assertSame('plan', $muatan['intent']);
        $this->assertStringContainsString('kontrol 1 bulan', $muatan['description']);
    }

    /**
     * Rencana dari kunjungan yang sudah selesai bukan rencana yang masih
     * berjalan — kalau selamanya 'active', fasilitas lain mengira pasien
     * masih menjalani program yang sudah tuntas.
     */
    #[Test]
    public function careplan_dari_asesmen_final_dilaporkan_completed(): void
    {
        $asesmen = $this->asesmenFinal();

        $muatan = $this->sinkron->syncCarePlan($asesmen->id)->request_payload;

        $this->assertSame('completed', $muatan['status']);
    }

    #[Test]
    public function asesmen_tanpa_rencana_tidak_menghasilkan_careplan(): void
    {
        $asesmen = $this->asesmenFinal(isi: [
            'chief_complaint' => 'Kontrol rutin.',
            'assessment' => 'Kondisi stabil.',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Rencana tanpa isi bukan rencana');

        $this->sinkron->syncCarePlan($asesmen->id);
    }

    /** Satu asesmen bisa menghasilkan satu resource saja, dan itu wajar. */
    #[Test]
    public function pengiriman_sekaligus_melaporkan_yang_dilewati_berikut_alasannya(): void
    {
        $registrasi = $this->daftarkan();
        $this->asesmenFinal(registrasi: $registrasi, isi: [
            'chief_complaint' => 'Kontrol rutin.',
            'assessment' => 'Kondisi stabil.',
        ]);

        $hasil = $this->sinkron->syncEncounterNotes($registrasi->id);

        $this->assertCount(1, $hasil['clinicalimpression']);
        $this->assertSame([], $hasil['careplan']);
        $this->assertCount(1, $hasil['dilewati']);
        $this->assertStringContainsString('CarePlan', $hasil['dilewati'][0]);
    }

    // ------------------------------------------------------------------ diet

    #[Test]
    public function pesanan_diet_terkirim_sebagai_nutritionorder_bukan_intake(): void
    {
        $diet = $this->pesananDiet();

        $muatan = $this->sinkron->syncDiet($diet->id)->request_payload;

        $this->assertSame('NutritionOrder', $muatan['resourceType']);
        $this->assertSame('order', $muatan['intent']);
        $this->assertSame('active', $muatan['status']);
        $this->assertSame('rendah-garam', $muatan['oralDiet']['type'][0]['coding'][0]['code']);
        $this->assertSame('Diet Rendah Garam', $muatan['oralDiet']['type'][0]['text']);
    }

    /**
     * ATURAN KETIGA: diet yang dihentikan tetap dikirim.
     */
    #[Test]
    public function diet_yang_dihentikan_dikirim_sebagai_revoked(): void
    {
        $diet = $this->pesananDiet();
        app(DietOrderService::class)->stop($diet);

        $muatan = $this->sinkron->syncDiet($diet->id)->request_payload;

        $this->assertSame('revoked', $muatan['status']);
    }

    #[Test]
    public function diet_yang_tidak_ada_ditolak_dengan_pesan_yang_jelas(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak ditemukan');

        $this->sinkron->syncDiet(99999);
    }

    // ------------------------------------------------------------- prasyarat

    #[Test]
    public function kunjungan_yang_belum_disinkronkan_menghentikan_pengiriman(): void
    {
        $asesmen = $this->asesmenFinal(petakan: false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('harus sudah disinkronkan');

        $this->sinkron->syncClinicalImpression($asesmen->id);
    }

    /**
     * Praktisi yang belum dipetakan TIDAK menahan catatan klinis: isinya
     * tetap sah dan berguna, dan menahannya berarti menahan rekam medis
     * karena alasan administratif.
     */
    #[Test]
    public function praktisi_yang_belum_dipetakan_tidak_menahan_catatan_klinis(): void
    {
        $asesmen = $this->asesmenFinal(petakanDokter: false);

        $muatan = $this->sinkron->syncClinicalImpression($asesmen->id)->request_payload;

        $this->assertArrayNotHasKey('assessor', $muatan);
        $this->assertNotEmpty($muatan['description']);
    }

    #[Test]
    public function pengiriman_ulang_memakai_id_yang_sama(): void
    {
        $asesmen = $this->asesmenFinal();

        $pertama = $this->sinkron->syncClinicalImpression($asesmen->id);
        $kedua = $this->sinkron->syncClinicalImpression($asesmen->id);

        $this->assertSame($pertama->external_reference, $kedua->external_reference);
        $this->assertSame(2, $kedua->attempt_count);
    }

    // ------------------------------------------------------------------ bantu

    private function asesmenFinal(
        ?Registration $registrasi = null,
        bool $finalkan = true,
        bool $petakan = true,
        bool $petakanDokter = true,
        ?array $isi = null,
    ): Assessment {
        $registrasi ??= $this->daftarkan();

        $asesmen = $this->klinis->openAssessment($registrasi->id, Assessment::KIND_SOAP, $this->dokter);

        $this->klinis->saveAssessment($asesmen, $isi ?? [
            'chief_complaint' => 'Gula darah naik sejak seminggu.',
            'subjective' => 'Pasien mengeluh lemas dan sering haus.',
            'objective' => 'GDS 260 mg/dL, TD 140/90.',
            'assessment' => 'Diabetes tidak terkontrol dengan hipertensi.',
            'plan' => 'Sesuaikan dosis metformin, kontrol 1 bulan.',
        ], $this->dokter);

        if ($petakan) {
            $this->siapkanPemetaan($registrasi, $petakanDokter);
        }

        if ($finalkan) {
            $this->klinis->finalizeAssessment($asesmen->refresh(), $this->dokter);
        }

        return $asesmen->refresh();
    }

    private function pesananDiet(): DietOrder
    {
        $registrasi = $this->daftarkan(careType: 'ranap');

        $admisi = app(AdmissionService::class)->admit($registrasi->id, $this->bedKosong(), $this->dokter->id);

        $this->siapkanPemetaan($registrasi);

        return app(DietOrderService::class)->order($admisi, [
            'diet_type' => 'rendah-garam',
            'start_date' => now()->toDateString(),
        ], $this->dokter);
    }

    /** Kamar dan bed dibuat di sini — ReferenceDataSeeder tidak memuatnya. */
    private function bedKosong(): Bed
    {
        static $urut = 0;
        $urut++;

        $kamar = Room::query()->create([
            'room_number' => 'CAT-' . $urut,
            'room_class' => 'kelas-3',
            'daily_rate' => 350000,
            'is_active' => true,
        ]);

        return Bed::query()->create([
            'room_id' => $kamar->id,
            'bed_number' => 'A',
            'status' => Bed::STATUS_TERSEDIA,
        ]);
    }

    private function siapkanPemetaan(Registration $registrasi, bool $petakanDokter = true): void
    {
        $this->pemetaan->setManually(
            'satusehat', 'patient', 'identity', $registrasi->patient_id,
            'P-' . $registrasi->patient_id, $this->dokter->id
        );

        $this->pemetaan->setManually(
            'satusehat', 'encounter', 'encounter', $registrasi->id,
            'E-' . $registrasi->id, $this->dokter->id
        );

        if ($petakanDokter && $registrasi->practitioner_id !== null) {
            $this->pemetaan->setManually(
                'satusehat', 'practitioner', 'organization', $registrasi->practitioner_id,
                'PR-' . $registrasi->practitioner_id, $this->dokter->id
            );
        }
    }

    private function daftarkan(string $careType = 'ralan'): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Catatan ' . $urut, 'sex' => 'P', 'birth_date' => '1968-04-04',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
            practitionerId: Practitioner::query()->where('is_active', true)->value('id'),
            extra: ['care_type' => $careType],
        );
    }
}
