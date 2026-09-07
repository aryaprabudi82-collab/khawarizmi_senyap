<?php

namespace Tests\Feature\Clinical;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Clinical\Models\Diagnosis;
use App\Modules\Clinical\Models\MedicalConsultation;
use App\Modules\Clinical\Models\PatientTransfer;
use App\Modules\Clinical\Services\ClinicalException;
use App\Modules\Clinical\Services\ConsultationTransferService;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Organization\Models\Practitioner;
use App\Modules\Organization\Models\Unit;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Konsultasi medik & transfer antar ruang (domain M item N).
 *
 * Yang paling perlu dikunci:
 *
 * 1. JAWABAN KONSULTASI WAJIB MENYEBUT PENJAWABNYA —
 *    jawaban_konsultasi_medik Khanza tidak punya kolomnya sama sekali.
 * 2. YANG MENJAWAB BOLEH BERBEDA DARI YANG DITANYA, dan bedanya
 *    tercatat.
 * 3. ALAT YANG MENYERTAI PASIEN ADALAH DAFTAR, bukan satu pilihan.
 * 4. ASAL RUANG DISALIN DARI ADMISI, diagnosis dibekukan dari rekam
 *    medis.
 * 5. PENYERAH DAN PENERIMA KEDUANYA WAJIB.
 */
class ConsultationTransferTest extends TestCase
{
    use RefreshDatabase;

    private ConsultationTransferService $layanan;

    private User $dokter;

    private User $konsulen;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->layanan = app(ConsultationTransferService::class);

        $this->dokter = User::query()->create([
            'username' => 'uji-konsul-minta', 'name' => 'dr. Peminta',
            'password' => 'password', 'is_active' => true,
        ]);

        $this->konsulen = User::query()->create([
            'username' => 'uji-konsul-jawab', 'name' => 'dr. Konsulen Jaga, Sp.JP',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    // ============================================== konsultasi

    #[Test]
    public function jawaban_konsultasi_mencatat_siapa_yang_menjawab(): void
    {
        $konsul = $this->minta();

        $dijawab = $this->layanan->answer(
            $konsul,
            'Lanjutkan antiplatelet, tambahkan beta blocker dosis rendah.',
            [],
            $this->konsulen
        );

        // jawaban_konsultasi_medik Khanza hanya punya empat kolom, dan
        // tidak satu pun menyebut penjawabnya.
        $this->assertSame('dr. Konsulen Jaga, Sp.JP', $dijawab->answering_practitioner_name);
        $this->assertSame(MedicalConsultation::DIJAWAB, $dijawab->status);
        $this->assertNotNull($dijawab->answered_at);
    }

    #[Test]
    public function penjawab_yang_berbeda_dari_yang_ditanya_bisa_dibedakan(): void
    {
        $konsul = $this->minta(['consulted_practitioner_name' => 'dr. Konsultan Tetap, Sp.JP']);

        $dijawab = $this->layanan->answer($konsul, 'Setuju rencana.', [], $this->konsulen);

        // Bukan kesalahan — konsulen jaga memang sering yang menjawab.
        // Yang salah adalah tidak bisa membedakannya.
        $this->assertTrue($dijawab->answeredBySomeoneElse());
        $this->assertSame('dr. Konsultan Tetap, Sp.JP', $dijawab->consulted_practitioner_name);
        $this->assertSame('dr. Konsulen Jaga, Sp.JP', $dijawab->answering_practitioner_name);
    }

    #[Test]
    public function jawaban_tanpa_nama_penjawab_ditolak(): void
    {
        $konsul = $this->minta();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/ditanyakan kembali kepada penulisnya/');

        $this->layanan->answer($konsul, 'Setuju rencana.', [], null);
    }

    #[Test]
    public function basis_data_menolak_status_dijawab_tanpa_penjawab(): void
    {
        $konsul = $this->minta();

        $this->expectException(QueryException::class);

        MedicalConsultation::query()->whereKey($konsul->id)->update([
            'status' => MedicalConsultation::DIJAWAB,
            'answered_at' => now(),
            'answer' => 'Jawaban tanpa penjawab.',
        ]);
    }

    #[Test]
    public function konsultasi_yang_sudah_dijawab_tidak_bisa_ditimpa(): void
    {
        $konsul = $this->minta();
        $this->layanan->answer($konsul, 'Jawaban pertama.', [], $this->konsulen);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/dicatat sebagai konsultasi evaluasi/');

        $this->layanan->answer($konsul->refresh(), 'Jawaban kedua.', [], $this->konsulen);
    }

    #[Test]
    public function konsultasi_tanpa_pertanyaan_ditolak(): void
    {
        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/memaksa konsulen menebak/');

        $this->minta(['question' => '   ']);
    }

    #[Test]
    public function konsultasi_cito_ditagih_lebih_cepat_daripada_yang_rutin(): void
    {
        $cito = $this->minta(['urgency' => 'cito', 'requested_at' => now()->subHours(2)]);
        $biasa = $this->minta(['urgency' => 'biasa', 'requested_at' => now()->subHours(2)]);

        // Kesegeraan tidak ada di Khanza; tanpa itu konsultasi cito
        // mengantre di belakang yang rutin.
        $this->assertTrue($cito->isOverdue());
        $this->assertFalse($biasa->isOverdue());

        $tertunggak = $this->layanan->overdueConsultations()->pluck('id')->all();
        $this->assertContains($cito->id, $tertunggak);
        $this->assertNotContains($biasa->id, $tertunggak);
    }

    #[Test]
    public function lama_menunggu_dihitung_bukan_disimpan(): void
    {
        $konsul = $this->minta(['requested_at' => now()->subHours(3)]);
        $dijawab = $this->layanan->answer($konsul, 'Sudah dinilai.', [], $this->konsulen);

        $this->assertGreaterThan(2.9, $dijawab->waitingHours());
        $this->assertArrayNotHasKey('waiting_hours', $dijawab->getAttributes());
    }

    #[Test]
    public function jenis_permintaan_di_luar_kosakata_ditolak(): void
    {
        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches("/'ngobrol' tidak dikenali/");

        $this->minta(['kind' => 'ngobrol']);
    }

    #[Test]
    public function konsultasi_bisa_dibatalkan_dengan_alasan(): void
    {
        $konsul = $this->minta();

        $batal = $this->layanan->cancelConsultation($konsul, 'Pasien keburu pulang atas permintaan sendiri');

        $this->assertSame(MedicalConsultation::DIBATALKAN, $batal->status);
        $this->assertNotContains($batal->id, $this->layanan->consultationsFor($konsul->registration_id)->pluck('id')->all());
    }

    #[Test]
    public function konsultasi_yang_dibatalkan_tidak_bisa_dijawab(): void
    {
        $konsul = $this->layanan->cancelConsultation($this->minta(), 'Salah kirim');

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/tidak bisa dijawab/');

        $this->layanan->answer($konsul, 'Terlanjur dijawab.', [], $this->konsulen);
    }

    #[Test]
    public function nomor_permintaan_berurutan_dan_unik(): void
    {
        $pertama = $this->minta();
        $kedua = $this->minta();

        $this->assertStringStartsWith('KM', $pertama->request_number);
        $this->assertNotSame($pertama->request_number, $kedua->request_number);
    }

    // ============================================== transfer

    #[Test]
    public function alat_yang_menyertai_pasien_boleh_lebih_dari_satu(): void
    {
        $kunjungan = $this->daftarkan();

        // Persis kasus yang tidak muat di enum Khanza.
        $transfer = $this->layanan->transfer($kunjungan->id, [
            'indication' => 'kondisi-memburuk',
            'accompanying_equipment' => ['oksigen-portabel', 'infus', 'kateter-urin'],
            'received_by_name' => 'Ns. Penerima',
        ], $this->dokter);

        $this->assertSame(
            ['oksigen-portabel', 'infus', 'kateter-urin'],
            $transfer->accompanying_equipment
        );
        $this->assertContains('oksigen-portabel', $transfer->riskyEquipment());
    }

    #[Test]
    public function alat_di_luar_kosakata_ditolak(): void
    {
        $kunjungan = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/tidak dikenali: termos/');

        $this->layanan->transfer($kunjungan->id, [
            'indication' => 'kondisi-stabil',
            'accompanying_equipment' => ['infus', 'termos'],
            'received_by_name' => 'Ns. Penerima',
        ], $this->dokter);
    }

    #[Test]
    public function pasien_tanpa_alat_apa_pun_adalah_jawaban_yang_sah(): void
    {
        $kunjungan = $this->daftarkan();

        $transfer = $this->layanan->transfer($kunjungan->id, [
            'indication' => 'kondisi-stabil',
            'accompanying_equipment' => [],
            'received_by_name' => 'Ns. Penerima',
        ], $this->dokter);

        $this->assertSame([], $transfer->accompanying_equipment);
        $this->assertSame([], $transfer->riskyEquipment());
    }

    #[Test]
    public function diagnosis_dibekukan_dari_rekam_medis_bukan_diketik(): void
    {
        $kunjungan = $this->daftarkan();
        $this->diagnosis($kunjungan, 'I50.9', 'Gagal jantung', Diagnosis::RANK_UTAMA);
        $this->diagnosis($kunjungan, 'N18.3', 'Penyakit ginjal kronik');
        $this->diagnosis($kunjungan, 'E11.9', 'Diabetes melitus tipe 2');

        $transfer = $this->layanan->transfer($kunjungan->id, [
            'indication' => 'butuh-fasilitas-lebih',
            'received_by_name' => 'Ns. ICU',
        ], $this->dokter);

        // Khanza menyediakan diagnosa_sekunder varchar(150) untuk SELURUH
        // diagnosis sekunder sekaligus.
        $this->assertCount(3, $transfer->diagnoses);
        $this->assertSame('I50.9', $transfer->diagnoses[0]['code']);
        $this->assertSame('utama', $transfer->diagnoses[0]['rank']);
    }

    #[Test]
    public function penyerah_dan_penerima_keduanya_wajib(): void
    {
        $kunjungan = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/yang tercatat sepihak bukan serah terima/');

        $this->layanan->transfer($kunjungan->id, [
            'indication' => 'kondisi-stabil',
        ], $this->dokter);
    }

    #[Test]
    public function indikasi_lain_lain_wajib_menyebut_keterangannya(): void
    {
        $kunjungan = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/saat mutu perawatan dipertanyakan/');

        $this->layanan->transfer($kunjungan->id, [
            'indication' => 'lain-lain',
            'received_by_name' => 'Ns. Penerima',
        ], $this->dokter);
    }

    #[Test]
    public function basis_data_menolak_lain_lain_tanpa_keterangan(): void
    {
        $kunjungan = $this->daftarkan();
        $transfer = $this->layanan->transfer($kunjungan->id, [
            'indication' => 'lain-lain',
            'indication_note' => 'Permintaan keluarga',
            'received_by_name' => 'Ns. Penerima',
        ], $this->dokter);

        $this->expectException(QueryException::class);

        PatientTransfer::query()->whereKey($transfer->id)->update(['indication_note' => '']);
    }

    #[Test]
    public function cara_pemindahan_di_luar_kosakata_ditolak(): void
    {
        $kunjungan = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches("/'digendong' tidak dikenali/");

        $this->layanan->transfer($kunjungan->id, [
            'indication' => 'kondisi-stabil',
            'transport_method' => 'digendong',
            'received_by_name' => 'Ns. Penerima',
        ], $this->dokter);
    }

    #[Test]
    public function perpindahan_antar_unit_bisa_dibedakan_dari_pindah_kamar(): void
    {
        $kunjungan = $this->daftarkan();

        $antarUnit = $this->layanan->transfer($kunjungan->id, [
            'indication' => 'kondisi-memburuk',
            'from_unit_name' => 'Bangsal Bedah',
            'to_unit_name' => 'ICU',
            'received_by_name' => 'Ns. ICU',
        ], $this->dokter);

        $dalamUnit = $this->layanan->transfer($kunjungan->id, [
            'indication' => 'kondisi-stabil',
            'from_unit_name' => 'Bangsal Bedah',
            'to_unit_name' => 'Bangsal Bedah',
            'received_by_name' => 'Ns. Bedah',
        ], $this->dokter);

        $this->assertTrue($antarUnit->crossesUnit());
        $this->assertFalse($dalamUnit->crossesUnit());
    }

    #[Test]
    public function persetujuan_keluarga_bisa_dicatat(): void
    {
        $kunjungan = $this->daftarkan();

        $transfer = $this->layanan->transfer($kunjungan->id, [
            'indication' => 'butuh-tenaga-lebih-ahli',
            'received_by_name' => 'Ns. ICU',
            'consent_given_by' => 'Rina Marlina',
            'consent_relation' => 'Istri',
        ], $this->dokter);

        $this->assertSame('Rina Marlina', $transfer->consent_given_by);
    }

    #[Test]
    public function tanda_vital_tidak_punya_kolom_di_catatan_transfer(): void
    {
        $kunjungan = $this->daftarkan();
        $transfer = $this->layanan->transfer($kunjungan->id, [
            'indication' => 'kondisi-stabil',
            'received_by_name' => 'Ns. Penerima',
        ], $this->dokter);

        // Khanza mengulang td/nadi/rr/suhu dua kali: sebelum dan sesudah
        // transfer. Panel observasi sejak item D sudah menanganinya.
        foreach (['td_sebelum_transfer', 'blood_pressure', 'pulse', 'temperature'] as $kolom) {
            $this->assertArrayNotHasKey($kolom, $transfer->getAttributes());
        }
    }

    // ---------------------------------------------------------------- fixture

    private function minta(array $data = []): MedicalConsultation
    {
        $kunjungan = $data['registration'] ?? $this->daftarkan();

        return $this->layanan->request($kunjungan->id, $data + [
            'kind' => 'konsultasi',
            'question' => 'Mohon penilaian kelayakan operasi dari sisi jantung.',
            'working_diagnosis' => 'Hernia inguinalis dengan riwayat infark miokard',
        ], $this->dokter);
    }

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
            'diagnosed_at' => now(),
        ]);
    }

    private function daftarkan(): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Konsul '.$urut, 'sex' => 'L', 'birth_date' => '1962-12-12',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
            practitionerId: Practitioner::query()->where('is_active', true)->value('id'),
        );
    }
}
