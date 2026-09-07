<?php

namespace Tests\Feature\Clinical;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Clinical\Models\Diagnosis;
use App\Modules\Clinical\Models\DischargeSummary;
use App\Modules\Clinical\Services\ClinicalException;
use App\Modules\Clinical\Services\DischargeSummaryService;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Inpatient\Models\Admission;
use App\Modules\Inpatient\Models\Room;
use App\Modules\Inpatient\Services\AdmissionService;
use App\Modules\Inpatient\Services\RoomService;
use App\Modules\Organization\Models\Practitioner;
use App\Modules\Organization\Models\Unit;
use App\Modules\Pharmacy\Database\Seeders\PharmacySeeder;
use App\Modules\Pharmacy\Models\Drug;
use App\Modules\Pharmacy\Models\Prescription;
use App\Modules\Pharmacy\Models\StockLocation;
use App\Modules\Pharmacy\Services\PrescriptionService;
use App\Modules\Pharmacy\Services\StockLedger;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Resume medis (domain M item H).
 *
 * Yang paling perlu dikunci:
 *
 * 1. SELURUH DIAGNOSIS IKUT, BERAPA PUN JUMLAHNYA. Khanza menyediakan
 *    diagnosa_utama sampai diagnosa_sekunder4 sebagai kolom; pasien
 *    dengan lima diagnosis sekunder kehilangan satu tanpa pesan apa pun.
 *    Uji pertama di bawah ini persis kasus itu.
 * 2. ISINYA DISALIN DARI REKAM MEDIS, bukan diketik ulang — supaya
 *    resume tidak bisa berbeda dari rekam medis yang diringkasnya.
 * 3. KONDISI PULANG DISALIN DARI ADMISI, tidak ditanyakan ulang.
 * 4. RESUME RANAP TIDAK BISA DIFINALKAN SEBELUM PASIEN PULANG.
 */
class DischargeSummaryTest extends TestCase
{
    use RefreshDatabase;

    private DischargeSummaryService $resume;

    private AdmissionService $admissions;

    private RoomService $rooms;

    private User $dokter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PermissionCatalogSeeder::class, RoleSeeder::class,
            ReferenceDataSeeder::class, PharmacySeeder::class,
        ]);

        $this->resume = app(DischargeSummaryService::class);
        $this->admissions = app(AdmissionService::class);
        $this->rooms = app(RoomService::class);

        $this->dokter = User::query()->create([
            'username' => 'uji-resume', 'name' => 'dr. Penanggung Jawab',
            'password' => 'password', 'is_active' => true,
        ]);
        $this->dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());
    }

    // ------------------------------------------------- inti: menyalin, bukan mengetik

    #[Test]
    public function resume_membekukan_seluruh_diagnosis_bukan_hanya_empat_sekunder(): void
    {
        $kunjungan = $this->daftarkan();

        // Satu utama dan LIMA sekunder. Pada Khanza yang kelima tidak punya
        // kolom dan hilang tanpa pesan; di sini semuanya harus ikut.
        $this->diagnosis($kunjungan, 'I10', 'Hipertensi esensial', Diagnosis::RANK_UTAMA);
        $this->diagnosis($kunjungan, 'E11.9', 'Diabetes melitus tipe 2');
        $this->diagnosis($kunjungan, 'N18.3', 'Penyakit ginjal kronik stadium 3');
        $this->diagnosis($kunjungan, 'D64.9', 'Anemia');
        $this->diagnosis($kunjungan, 'E78.5', 'Dislipidemia');
        $this->diagnosis($kunjungan, 'K21.0', 'Refluks gastroesofageal');

        $resume = $this->resume->open($kunjungan->id, $this->dokter);
        $final = $this->resume->finalize($resume, $this->dokter);

        $this->assertCount(6, $final->diagnoses);

        $kode = array_column($final->diagnoses, 'code');
        foreach (['I10', 'E11.9', 'N18.3', 'D64.9', 'E78.5', 'K21.0'] as $satu) {
            $this->assertContains($satu, $kode, "Diagnosis {$satu} hilang dari resume.");
        }

        // Utama selalu di depan: itu jawaban atas pertanyaan pertama
        // fasilitas berikutnya — pasien ini dirawat karena apa.
        $this->assertSame('I10', $final->diagnoses[0]['code']);
        $this->assertSame('utama', $final->diagnoses[0]['rank']);
        $this->assertSame('I10', $final->primaryDiagnosis()['code']);
    }

    #[Test]
    public function isi_resume_disalin_apa_adanya_dari_rekam_medis(): void
    {
        $kunjungan = $this->daftarkan();
        $diagnosis = $this->diagnosis($kunjungan, 'J18.9', 'Pneumonia', Diagnosis::RANK_UTAMA);

        $final = $this->resume->finalize(
            $this->resume->open($kunjungan->id, $this->dokter),
            $this->dokter
        );

        $beku = $final->diagnoses[0];
        $this->assertSame($diagnosis->display, $beku['display']);
        $this->assertSame($diagnosis->certainty, $beku['certainty']);
    }

    #[Test]
    public function resume_yang_sudah_final_tidak_ikut_berubah_saat_diagnosis_direvisi(): void
    {
        $kunjungan = $this->daftarkan();
        $diagnosis = $this->diagnosis($kunjungan, 'A09', 'Diare infeksius', Diagnosis::RANK_UTAMA);

        $final = $this->resume->finalize(
            $this->resume->open($kunjungan->id, $this->dokter),
            $this->dokter
        );

        // Koder memperbaiki ICD sebulan kemudian; resume yang sudah
        // ditandatangani tidak boleh ikut berubah.
        $diagnosis->update(['code' => 'A09.9', 'display' => 'Gastroenteritis, penyebab tak spesifik']);

        $this->assertSame('A09', $final->fresh()->diagnoses[0]['code']);
    }

    #[Test]
    public function resume_tidak_bisa_difinalkan_tanpa_diagnosis(): void
    {
        $resume = $this->resume->open($this->daftarkan()->id, $this->dokter);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/menyalin diagnosis dari rekam medis/');

        $this->resume->finalize($resume, $this->dokter);
    }

    #[Test]
    public function resume_tidak_bisa_difinalkan_tanpa_diagnosis_utama(): void
    {
        $kunjungan = $this->daftarkan();
        $this->diagnosis($kunjungan, 'E78.5', 'Dislipidemia');

        $resume = $this->resume->open($kunjungan->id, $this->dokter);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/Diagnosis utama belum ditetapkan/');

        $this->resume->finalize($resume, $this->dokter);
    }

    #[Test]
    public function resume_tanpa_dpjp_tidak_bisa_difinalkan(): void
    {
        $kunjungan = $this->daftarkanTanpaDokter();
        $this->diagnosis($kunjungan, 'I10', 'Hipertensi esensial', Diagnosis::RANK_UTAMA);

        $resume = $this->resume->open($kunjungan->id, $this->dokter);
        $this->assertNull($resume->dpjp_name);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/DPJP wajib disebut/');

        $this->resume->finalize($resume, $this->dokter);
    }

    // ------------------------------------------------------------- rawat inap

    #[Test]
    public function resume_ranap_tidak_bisa_difinalkan_sebelum_pasien_pulang(): void
    {
        $admisi = $this->rawatInap();
        $this->diagnosis($this->registrasiDari($admisi), 'J18.9', 'Pneumonia', Diagnosis::RANK_UTAMA);

        $resume = $this->resume->open($admisi->registration_id, $this->dokter);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/belum dinyatakan pulang/');

        $this->resume->finalize($resume, $this->dokter);
    }

    #[Test]
    public function kondisi_pulang_disalin_dari_admisi_tidak_ditanyakan_ulang(): void
    {
        $admisi = $this->rawatInap('K010', 'B01');
        $this->diagnosis($this->registrasiDari($admisi), 'I21.9', 'Infark miokard akut', Diagnosis::RANK_UTAMA);

        $resume = $this->resume->open($admisi->registration_id, $this->dokter);
        $this->admissions->discharge($admisi->refresh(), 'meninggal', 'Henti jantung', $this->dokter->id);

        $final = $this->resume->finalize($resume->refresh(), $this->dokter);

        $this->assertSame(DischargeSummary::MENINGGAL, $final->condition_at_discharge);
        $this->assertSame('meninggal', $final->discharge_manner);
        $this->assertNotNull($final->discharged_at);
    }

    #[Test]
    public function pasien_yang_pulang_sembuh_tercatat_hidup(): void
    {
        $admisi = $this->rawatInap('K011', 'B01');
        $this->diagnosis($this->registrasiDari($admisi), 'A09', 'Diare infeksius', Diagnosis::RANK_UTAMA);

        $resume = $this->resume->open($admisi->registration_id, $this->dokter);
        $this->admissions->discharge($admisi->refresh(), 'sembuh', null, $this->dokter->id);

        $final = $this->resume->finalize($resume->refresh(), $this->dokter);

        $this->assertSame(DischargeSummary::HIDUP, $final->condition_at_discharge);
        $this->assertSame('sembuh', $final->discharge_manner);
    }

    #[Test]
    public function admisi_yang_pasiennya_pulang_tanpa_resume_final_bisa_ditagih(): void
    {
        $admisi = $this->rawatInap('K012', 'B01');
        $this->admissions->discharge($admisi->refresh(), 'sembuh', null, $this->dokter->id);

        $tertunggak = $this->resume->outstanding()->pluck('admission_id')->all();
        $this->assertContains($admisi->id, $tertunggak);

        $this->diagnosis($this->registrasiDari($admisi), 'A09', 'Diare infeksius', Diagnosis::RANK_UTAMA);
        $this->resume->finalize($this->resume->open($admisi->registration_id, $this->dokter), $this->dokter);

        $this->assertNotContains(
            $admisi->id,
            $this->resume->outstanding()->pluck('admission_id')->all()
        );
    }

    // --------------------------------------------------------------- obat pulang

    #[Test]
    public function obat_pulang_diambil_dari_resep_yang_benar_benar_diserahkan(): void
    {
        $kunjungan = $this->daftarkan();
        $this->diagnosis($kunjungan, 'I10', 'Hipertensi esensial', Diagnosis::RANK_UTAMA);

        $this->resepDiserahkan($kunjungan);

        // Resep kedua diketik dokter tapi tidak pernah ditebus: bukan obat
        // yang dibawa pulang pasien, jadi tidak boleh muncul di resume.
        app(PrescriptionService::class)->create($kunjungan->id, $this->dokter, Prescription::KIND_PULANG);

        $final = $this->resume->finalize(
            $this->resume->open($kunjungan->id, $this->dokter),
            $this->dokter
        );

        $this->assertCount(1, $final->discharge_medications);
        $this->assertSame('2x1 tablet', $final->discharge_medications[0]['dosage_instruction']);
        // assertEquals, bukan assertSame: 2.0 yang ditulis ke jsonb kembali
        // sebagai int 2 setelah bolak-balik JSON.
        $this->assertEquals(2, $final->discharge_medications[0]['quantity']);
    }

    #[Test]
    public function resume_tanpa_resep_apa_pun_tetap_bisa_difinalkan(): void
    {
        $kunjungan = $this->daftarkan();
        $this->diagnosis($kunjungan, 'I10', 'Hipertensi esensial', Diagnosis::RANK_UTAMA);

        $final = $this->resume->finalize(
            $this->resume->open($kunjungan->id, $this->dokter),
            $this->dokter
        );

        $this->assertSame([], $final->discharge_medications);
    }

    // ---------------------------------------------------------- satu episode satu

    #[Test]
    public function membuka_dua_kali_melanjutkan_draf_yang_sama(): void
    {
        $kunjungan = $this->daftarkan();

        $pertama = $this->resume->open($kunjungan->id, $this->dokter);
        $kedua = $this->resume->open($kunjungan->id, $this->dokter);

        $this->assertSame($pertama->id, $kedua->id);
    }

    #[Test]
    public function kunjungan_yang_sudah_punya_resume_final_tidak_boleh_dibuatkan_lagi(): void
    {
        $kunjungan = $this->daftarkan();
        $this->diagnosis($kunjungan, 'I10', 'Hipertensi esensial', Diagnosis::RANK_UTAMA);
        $this->resume->finalize($this->resume->open($kunjungan->id, $this->dokter), $this->dokter);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/dua versi cerita yang sama/');

        $this->resume->open($kunjungan->id, $this->dokter);
    }

    #[Test]
    public function resume_final_tidak_bisa_diubah(): void
    {
        $kunjungan = $this->daftarkan();
        $this->diagnosis($kunjungan, 'I10', 'Hipertensi esensial', Diagnosis::RANK_UTAMA);
        $final = $this->resume->finalize($this->resume->open($kunjungan->id, $this->dokter), $this->dokter);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/tidak boleh berubah tanpa jejak/');

        $this->resume->save($final, ['chief_complaint' => 'Diubah diam-diam']);
    }

    #[Test]
    public function resume_yang_dibatalkan_boleh_diganti_yang_baru(): void
    {
        $kunjungan = $this->daftarkan();
        $this->diagnosis($kunjungan, 'I10', 'Hipertensi esensial', Diagnosis::RANK_UTAMA);
        $lama = $this->resume->finalize($this->resume->open($kunjungan->id, $this->dokter), $this->dokter);

        $this->resume->cancel($lama, 'Diagnosis utama salah');

        $baru = $this->resume->open($kunjungan->id, $this->dokter);
        $this->assertNotSame($lama->id, $baru->id);
        $this->assertStringContainsString('Dibatalkan: Diagnosis utama salah', $lama->fresh()->follow_up_instruction);
    }

    #[Test]
    public function pembatalan_tanpa_alasan_ditolak(): void
    {
        $resume = $this->resume->open($this->daftarkan()->id, $this->dokter);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/Alasan pembatalan wajib diisi/');

        $this->resume->cancel($resume, '   ');
    }

    #[Test]
    public function bagian_naratif_boleh_diisi_selama_masih_draf(): void
    {
        $resume = $this->resume->open($this->daftarkan()->id, $this->dokter);

        $terisi = $this->resume->save($resume, [
            'chief_complaint' => 'Sesak napas sejak tiga hari',
            'illness_course' => 'Membaik setelah pemberian antibiotik.',
            'follow_up_instruction' => 'Kontrol tujuh hari lagi.',
            'control_unit' => 'Poliklinik Penyakit Dalam',
            // Diagnosis TIDAK boleh diterima dari pemanggil: yang disalin
            // adalah rekam medis, dan menerimanya di sini membuka pintu
            // resume yang berbeda dari rekam medisnya.
            'diagnoses' => [['code' => 'PALSU', 'display' => 'Diketik manual']],
        ]);

        $this->assertSame('Sesak napas sejak tiga hari', $terisi->chief_complaint);
        $this->assertSame([], $terisi->diagnoses);
    }

    // ------------------------------------------------------------- basis data

    #[Test]
    public function basis_data_menolak_resume_final_tanpa_diagnosis(): void
    {
        $resume = $this->resume->open($this->daftarkan()->id, $this->dokter);

        $this->expectException(QueryException::class);

        // Menembus service langsung: aturannya harus dipegang basis data juga,
        // karena service bukan satu-satunya pintu ke tabel ini.
        DischargeSummary::query()->whereKey($resume->id)->update([
            'status' => DischargeSummary::FINAL,
            'finalized_at' => now(),
        ]);
    }

    #[Test]
    public function basis_data_menolak_dua_resume_aktif_untuk_satu_kunjungan(): void
    {
        $kunjungan = $this->daftarkan();
        $this->resume->open($kunjungan->id, $this->dokter);

        $this->expectException(QueryException::class);

        DischargeSummary::query()->create([
            'registration_id' => $kunjungan->id,
            'patient_id' => $kunjungan->patient_id,
            'registration_number' => $kunjungan->registration_number,
            'patient_mrn' => 'X', 'patient_name' => 'Duplikat',
            'diagnoses' => [], 'procedures' => [], 'discharge_medications' => [],
            'status' => DischargeSummary::DRAF,
        ]);
    }

    // ---------------------------------------------------------------- fixture

    private function diagnosis(
        Registration $kunjungan,
        string $kode,
        string $nama,
        string $rank = Diagnosis::RANK_SEKUNDER,
    ): Diagnosis {
        return Diagnosis::query()->create([
            'registration_id' => $kunjungan->id,
            'patient_id' => $kunjungan->patient_id,
            'registration_number' => $kunjungan->registration_number,
            'code' => $kode,
            'display' => $nama,
            'rank' => $rank,
            'certainty' => 'definitif',
            'practitioner_name' => 'dr. Penanggung Jawab',
            'diagnosed_at' => now(),
        ]);
    }

    private function resepDiserahkan(Registration $kunjungan): Prescription
    {
        $obat = Drug::query()->where('code', 'OBT-001')->firstOrFail();
        $depo = StockLocation::query()->where('code', 'DEPO-RJ')->firstOrFail();

        app(StockLedger::class)->receive(
            $obat->id, $depo->id, 'BATCH-RESUME-'.uniqid(), 50,
            now()->addYear()->toDateString(), 1500, $this->dokter
        );

        $resep = app(PrescriptionService::class)->create($kunjungan->id, $this->dokter, Prescription::KIND_PULANG);

        app(PrescriptionService::class)->addItem($resep, $obat->id, 2, '2x1 tablet');
        app(PrescriptionService::class)->submit($resep->refresh());
        app(PrescriptionService::class)->review($resep->refresh(), 'disetujui', null, $this->dokter);

        return app(PrescriptionService::class)->dispense($resep->refresh(), $depo->id, $this->dokter);
    }

    private function rawatInap(string $kamar = 'K001', string $bed = 'B01'): Admission
    {
        $room = Room::query()->where('room_number', $kamar)->first()
            ?? $this->rooms->createRoom(['room_number' => $kamar, 'room_class' => 'kelas-3']);

        $tempat = $this->rooms->addBed($room, $bed);

        return $this->admissions->admit($this->daftarkan(ranap: true)->id, $tempat, $this->dokter->id);
    }

    private function registrasiDari(Admission $admisi): Registration
    {
        return Registration::query()->findOrFail($admisi->registration_id);
    }

    private function daftarkan(bool $ranap = false): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Resume '.$urut, 'sex' => 'L', 'birth_date' => '1965-03-03',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
            practitionerId: Practitioner::query()->where('is_active', true)->value('id'),
            extra: $ranap ? ['care_type' => 'ranap'] : [],
        );
    }

    private function daftarkanTanpaDokter(): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Tanpa DPJP '.$urut, 'sex' => 'P', 'birth_date' => '1980-08-08',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
        );
    }
}
