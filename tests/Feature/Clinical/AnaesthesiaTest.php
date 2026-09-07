<?php

namespace Tests\Feature\Clinical;

use App\Modules\Catalog\Models\FormTemplate;
use App\Modules\Catalog\Models\Payer;
use App\Modules\Clinical\Models\AnaesthesiaRecord;
use App\Modules\Clinical\Models\FormResponse;
use App\Modules\Clinical\Models\Operation;
use App\Modules\Clinical\Models\PostoperativeOrder;
use App\Modules\Clinical\Models\RecoveryAssessment;
use App\Modules\Clinical\Services\AnaesthesiaService;
use App\Modules\Clinical\Services\ClinicalException;
use App\Modules\Clinical\Services\ClinicalRecordService;
use App\Modules\Clinical\Services\FormResponseService;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
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
 * Anestesi & pasca-operasi (domain M item M).
 *
 * Yang paling perlu dikunci:
 *
 * 1. MELEKAT PADA OPERASI, bukan pada kunjungan — pasien yang dioperasi
 *    dua kali punya dua catatan anestesi yang harus bisa dibedakan.
 * 2. SKOR PEMULIHAN TIDAK DISIMPAN ULANG: dibaca dari form_responses
 *    yang sudah menghitung dan membekukannya.
 * 3. SKOR YANG MASIH DRAF BUKAN SKOR.
 * 4. MEMINDAHKAN PASIEN DI BAWAH AMBANG ALDRETE WAJIB BERALASAN, bukan
 *    dilarang — itu pertimbangan dokter anestesi, bukan aturan program.
 * 5. INSTRUMEN HARUS COCOK DENGAN JENIS ANESTESINYA.
 */
class AnaesthesiaTest extends TestCase
{
    use RefreshDatabase;

    private AnaesthesiaService $anestesi;

    private FormResponseService $formulir;

    private User $dokterAnestesi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->anestesi = app(AnaesthesiaService::class);
        $this->formulir = app(FormResponseService::class);

        $this->dokterAnestesi = User::query()->create([
            'username' => 'uji-anestesi', 'name' => 'dr. Anestesi Uji, Sp.An',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    // ============================================== melekat pada operasi

    #[Test]
    public function dua_operasi_pada_satu_kunjungan_punya_catatan_anestesi_sendiri_sendiri(): void
    {
        $kunjungan = $this->daftarkan();

        $pertama = $this->operasi($kunjungan, 'OPR-APENDEKTOMI');
        $kedua = $this->operasi($kunjungan, 'OPR-HERNIOTOMI');

        $catatanPertama = $this->anestesi->open($pertama, [], $this->dokterAnestesi);
        $catatanKedua = $this->anestesi->open($kedua, [], $this->dokterAnestesi);

        // Ketiga tabel Khanza berkunci no_rawat saja: pembedahan ulang
        // karena perdarahan tidak bisa dibedakan dari yang pertama.
        $this->assertNotSame($catatanPertama->id, $catatanKedua->id);
        $this->assertSame($pertama->id, $catatanPertama->operation_id);
        $this->assertSame($kedua->id, $catatanKedua->operation_id);
    }

    #[Test]
    public function satu_operasi_satu_catatan_anestesi(): void
    {
        $operasi = $this->operasi();

        $pertama = $this->anestesi->open($operasi, [], $this->dokterAnestesi);
        $kedua = $this->anestesi->open($operasi, [], $this->dokterAnestesi);

        $this->assertSame($pertama->id, $kedua->id);
    }

    #[Test]
    public function catatan_menyalin_operator_dan_nama_tindakan_dari_operasinya(): void
    {
        $operasi = $this->operasi();
        $catatan = $this->anestesi->open($operasi, [], $this->dokterAnestesi);

        $this->assertSame($operasi->surgeon_name, $catatan->surgeon_name);
        $this->assertSame($operasi->service_name, $catatan->procedure_name);
    }

    #[Test]
    public function basis_data_menolak_dua_catatan_aktif_untuk_satu_operasi(): void
    {
        $operasi = $this->operasi();
        $this->anestesi->open($operasi, [], $this->dokterAnestesi);

        $this->expectException(QueryException::class);

        AnaesthesiaRecord::query()->create([
            'operation_id' => $operasi->id,
            'registration_id' => $operasi->registration_id,
            'patient_id' => $operasi->patient_id,
            'registration_number' => $operasi->registration_number,
            'patient_mrn' => $operasi->patient_mrn,
            'patient_name' => $operasi->patient_name,
            'status' => AnaesthesiaRecord::DRAF,
        ]);
    }

    // ============================================== waktu & durasi

    #[Test]
    public function lama_anestesi_dan_lama_bedah_dihitung_terpisah(): void
    {
        $catatan = $this->anestesi->save($this->buka(), [
            'anaesthesia_start_at' => now()->subMinutes(150),
            'surgery_start_at' => now()->subMinutes(130),
            'surgery_end_at' => now()->subMinutes(40),
            'anaesthesia_end_at' => now()->subMinutes(25),
        ]);

        // Keduanya memang tidak sama: anestesi mulai sebelum insisi dan
        // berakhir sesudah luka ditutup.
        $this->assertSame(125, $catatan->anaesthesiaMinutes());
        $this->assertSame(90, $catatan->surgeryMinutes());

        // Tidak ada kolom durasi yang bisa berbeda dari keempat waktunya.
        $this->assertArrayNotHasKey('anaesthesia_minutes', $catatan->getAttributes());
        $this->assertArrayNotHasKey('surgery_minutes', $catatan->getAttributes());
    }

    #[Test]
    public function basis_data_menolak_urutan_waktu_yang_terbalik(): void
    {
        $catatan = $this->buka();

        $this->expectException(QueryException::class);

        // Insisi sebelum anestesi mulai: durasinya akan negatif dan tetap
        // ditagihkan.
        AnaesthesiaRecord::query()->whereKey($catatan->id)->update([
            'anaesthesia_start_at' => now(),
            'surgery_start_at' => now()->subHour(),
        ]);
    }

    #[Test]
    public function kelas_asa_darurat_ditandai(): void
    {
        $catatan = $this->anestesi->save($this->buka(), ['asa_class' => '3E']);

        // ASA tidak ada di penilaian_pre_anestesi Khanza, padahal itu
        // penilaian risiko anestesi yang paling ringkas.
        $this->assertSame('3E', $catatan->asa_class);
        $this->assertTrue($catatan->isEmergency());
    }

    #[Test]
    public function kelas_asa_di_luar_kosakata_ditolak(): void
    {
        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches("/'7' tidak dikenali/");

        $this->anestesi->save($this->buka(), ['asa_class' => '7']);
    }

    #[Test]
    public function tanda_vital_tidak_punya_kolom_di_catatan_anestesi(): void
    {
        $catatan = $this->buka();

        // catatan_anestesi_sedasi Khanza memasang pre_induksi_td, _nadi,
        // _rr, _suhu, _o2; panel observasi sejak item D sudah menanganinya,
        // dan salinan kedua adalah dua tekanan darah yang bisa berbeda.
        foreach (['pre_induksi_td', 'blood_pressure', 'pulse', 'respiratory_rate', 'temperature'] as $kolom) {
            $this->assertArrayNotHasKey($kolom, $catatan->getAttributes());
        }
    }

    // ============================================== finalisasi

    #[Test]
    public function catatan_anestesi_tidak_bisa_difinalkan_tanpa_empat_hal_pokok(): void
    {
        $catatan = $this->buka();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/nama dokter anestesi.*waktu selesai anestesi/s');

        $this->anestesi->finalize($catatan, $this->dokterAnestesi);
    }

    #[Test]
    public function catatan_anestesi_lengkap_bisa_difinalkan(): void
    {
        $final = $this->catatanFinal();

        $this->assertSame(AnaesthesiaRecord::FINAL, $final->status);
        $this->assertNotNull($final->finalized_at);
    }

    #[Test]
    public function catatan_final_tidak_bisa_diubah(): void
    {
        $final = $this->catatanFinal();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/tidak bisa diubah/');

        $this->anestesi->save($final, ['analgesia' => 'Diubah diam-diam']);
    }

    // ============================================== skor pemulihan

    #[Test]
    public function skor_pemulihan_dibaca_dari_formulir_bukan_disimpan_ulang(): void
    {
        $catatan = $this->catatanUmum();
        $jawaban = $this->aldreteFinal($catatan->registration_id, 2);

        $penilaian = $this->anestesi->recordRecovery($catatan, $jawaban, [
            'decision' => RecoveryAssessment::PINDAH_BANGSAL,
        ], $this->dokterAnestesi);

        // Khanza menyimpan label, angka, DAN totalnya sekaligus di
        // skor_aldrette_pasca_anestesi — tiga tempat untuk satu kebenaran.
        $this->assertSame(10, $penilaian->score());
        $this->assertArrayNotHasKey('score', $penilaian->getAttributes());
        $this->assertArrayNotHasKey('total_nilai', $penilaian->getAttributes());
        $this->assertSame($jawaban->id, $penilaian->form_response_id);
    }

    #[Test]
    public function skor_yang_masih_draf_tidak_bisa_dipakai(): void
    {
        $catatan = $this->catatanUmum();

        $draf = $this->formulir->open($catatan->registration_id, RecoveryAssessment::ALDRETE, $this->dokterAnestesi);
        $this->formulir->save($draf, $this->jawabanAldrete(2));

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/masih bisa berubah/');

        $this->anestesi->recordRecovery($catatan, $draf->refresh(), [], $this->dokterAnestesi);
    }

    #[Test]
    public function formulir_yang_bukan_instrumen_pemulihan_ditolak(): void
    {
        $catatan = $this->catatanUmum();

        $lain = $this->formulir->open($catatan->registration_id, 'risiko-jatuh-dewasa', $this->dokterAnestesi);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/bukan instrumen pemulihan pasca anestesi/');

        $this->anestesi->recordRecovery($catatan, $lain, [], $this->dokterAnestesi);
    }

    #[Test]
    public function penilaian_milik_kunjungan_lain_ditolak(): void
    {
        $catatan = $this->catatanUmum();
        $kunjunganLain = $this->daftarkan();
        $jawabanLain = $this->aldreteFinal($kunjunganLain->id, 2);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/memindahkan pasien yang salah/');

        $this->anestesi->recordRecovery($catatan, $jawabanLain, [], $this->dokterAnestesi);
    }

    #[Test]
    public function bromage_tidak_dipakai_untuk_anestesi_umum(): void
    {
        $catatan = $this->catatanUmum();
        $jawaban = $this->bromageFinal($catatan->registration_id);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/tidak mengukur apa pun/');

        $this->anestesi->recordRecovery($catatan, $jawaban, [], $this->dokterAnestesi);
    }

    #[Test]
    public function bromage_dipakai_untuk_anestesi_spinal(): void
    {
        $catatan = $this->anestesi->save($this->buka(), ['anaesthesia_type' => 'spinal']);
        $jawaban = $this->bromageFinal($catatan->registration_id);

        $penilaian = $this->anestesi->recordRecovery($catatan, $jawaban, [], $this->dokterAnestesi);

        $this->assertSame(RecoveryAssessment::BROMAGE, $penilaian->instrument_code);
    }

    #[Test]
    public function memindahkan_pasien_di_bawah_ambang_aldrete_wajib_beralasan(): void
    {
        $catatan = $this->catatanUmum();
        $jawaban = $this->aldreteFinal($catatan->registration_id, 1);

        $this->assertSame(5, $jawaban->score);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/alasannya wajib dicatat/');

        $this->anestesi->recordRecovery($catatan, $jawaban, [
            'decision' => RecoveryAssessment::PINDAH_BANGSAL,
        ], $this->dokterAnestesi);
    }

    #[Test]
    public function memindahkan_pasien_di_bawah_ambang_tetap_boleh_bila_beralasan(): void
    {
        $catatan = $this->catatanUmum();
        $jawaban = $this->aldreteFinal($catatan->registration_id, 1);

        // Bukan dilarang: itu pertimbangan dokter anestesi, bukan aturan
        // yang boleh dikarang program.
        $penilaian = $this->anestesi->recordRecovery($catatan, $jawaban, [
            'decision' => RecoveryAssessment::PINDAH_BANGSAL,
            'decision_note' => 'Dipindah ke HCU dengan pemantauan ketat atas permintaan DPJP.',
        ], $this->dokterAnestesi);

        $this->assertSame(RecoveryAssessment::PINDAH_BANGSAL, $penilaian->decision);
        $this->assertNotNull($penilaian->decision_note);
    }

    #[Test]
    public function memindahkan_ke_icu_tidak_menuntut_pembenaran_tambahan(): void
    {
        $catatan = $this->catatanUmum();
        $jawaban = $this->aldreteFinal($catatan->registration_id, 0);

        // Skor rendah memang salah satu alasan pasien dikirim ke ICU;
        // meminta pembenaran atas keputusan yang lebih aman itu keliru.
        $penilaian = $this->anestesi->recordRecovery($catatan, $jawaban, [
            'decision' => RecoveryAssessment::PINDAH_ICU,
        ], $this->dokterAnestesi);

        $this->assertSame(RecoveryAssessment::PINDAH_ICU, $penilaian->decision);
    }

    #[Test]
    public function lanjut_observasi_tidak_menuntut_alasan(): void
    {
        $catatan = $this->catatanUmum();
        $jawaban = $this->aldreteFinal($catatan->registration_id, 1);

        $penilaian = $this->anestesi->recordRecovery($catatan, $jawaban, [
            'decision' => RecoveryAssessment::LANJUT_OBSERVASI,
        ], $this->dokterAnestesi);

        $this->assertSame(RecoveryAssessment::LANJUT_OBSERVASI, $penilaian->decision);
    }

    #[Test]
    public function pemulihan_dinilai_berulang_dan_urutannya_naik(): void
    {
        $catatan = $this->catatanUmum();

        $pertama = $this->anestesi->recordRecovery(
            $catatan, $this->aldreteFinal($catatan->registration_id, 1),
            ['decision' => RecoveryAssessment::LANJUT_OBSERVASI], $this->dokterAnestesi
        );
        $kedua = $this->anestesi->recordRecovery(
            $catatan, $this->aldreteFinal($catatan->registration_id, 2),
            ['decision' => RecoveryAssessment::PINDAH_BANGSAL], $this->dokterAnestesi
        );

        $this->assertSame(1, $pertama->sequence);
        $this->assertSame(2, $kedua->sequence);

        // Inilah gunanya dinilai berulang: perbaikan skornya yang jadi
        // dasar memutuskan pasien boleh keluar.
        $trend = $this->anestesi->recoveryTrend($catatan->operation_id);
        $this->assertSame([5, 10], $trend->map(fn ($p) => $p->score())->all());
    }

    #[Test]
    public function satu_jawaban_formulir_tidak_bisa_dipakai_dua_kali(): void
    {
        $catatan = $this->catatanUmum();
        $jawaban = $this->aldreteFinal($catatan->registration_id, 2);

        $this->anestesi->recordRecovery($catatan, $jawaban, [], $this->dokterAnestesi);

        $this->expectException(QueryException::class);

        // Kalau boleh, satu skor yang sama terhitung sebagai dua penilaian.
        $this->anestesi->recordRecovery($catatan, $jawaban, [], $this->dokterAnestesi);
    }

    // ============================================== instruksi pasca-operasi

    #[Test]
    public function instruksi_pasca_operasi_melekat_pada_operasinya(): void
    {
        $operasi = $this->operasi();

        $instruksi = $this->anestesi->orderPostoperativeCare($operasi, [
            'care_location' => 'Bangsal bedah kelas 3',
            'analgesics' => 'Ketorolak 30 mg IV tiap 8 jam',
            'mobilisation' => 'Duduk 6 jam pasca operasi, jalan 12 jam.',
        ], [], $this->dokterAnestesi);

        $this->assertSame($operasi->id, $instruksi->operation_id);
        $this->assertContains('Mobilisasi', $instruksi->filledParts());
    }

    #[Test]
    public function instruksi_pasca_operasi_kosong_ditolak(): void
    {
        $operasi = $this->operasi();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/tidak tahu apa yang harus dikerjakan/');

        $this->anestesi->orderPostoperativeCare($operasi, [
            'diet' => '  ', 'fluids' => null,
        ], [], $this->dokterAnestesi);
    }

    #[Test]
    public function basis_data_menolak_instruksi_pasca_operasi_kosong(): void
    {
        $operasi = $this->operasi();
        $instruksi = $this->anestesi->orderPostoperativeCare(
            $operasi, ['diet' => 'Bubur saring'], [], $this->dokterAnestesi
        );

        $this->expectException(QueryException::class);

        PostoperativeOrder::query()
            ->whereKey($instruksi->id)->update(['diet' => '']);
    }

    #[Test]
    public function instruksi_tanpa_nama_dokter_ditolak(): void
    {
        $operasi = $this->operasi();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/berhak tahu dari siapa/');

        $this->anestesi->orderPostoperativeCare($operasi, ['diet' => 'Bubur saring'], [], null);
    }

    // ---------------------------------------------------------------- fixture

    private function catatanFinal(): AnaesthesiaRecord
    {
        $catatan = $this->anestesi->save($this->buka(), [
            'anaesthetist_name' => 'dr. Anestesi Uji, Sp.An',
            'anaesthesia_type' => 'umum',
            'airway' => 'ett',
            'asa_class' => '2',
            'anaesthesia_start_at' => now()->subMinutes(120),
            'anaesthesia_end_at' => now()->subMinutes(20),
        ]);

        return $this->anestesi->finalize($catatan->refresh(), $this->dokterAnestesi);
    }

    private function catatanUmum(): AnaesthesiaRecord
    {
        return $this->anestesi->save($this->buka(), ['anaesthesia_type' => 'umum']);
    }

    private function buka(): AnaesthesiaRecord
    {
        return $this->anestesi->open($this->operasi(), [], $this->dokterAnestesi);
    }

    private function aldreteFinal(int $registrationId, int $nilai): FormResponse
    {
        $jawaban = $this->formulir->open($registrationId, RecoveryAssessment::ALDRETE, $this->dokterAnestesi);
        $this->formulir->save($jawaban, $this->jawabanAldrete($nilai));

        return $this->formulir->finalize($jawaban->refresh(), $this->dokterAnestesi);
    }

    private function bromageFinal(int $registrationId): FormResponse
    {
        $jawaban = $this->formulir->open($registrationId, RecoveryAssessment::BROMAGE, $this->dokterAnestesi);
        $template = FormTemplate::query()
            ->where('code', RecoveryAssessment::BROMAGE)->firstOrFail();

        $isi = [];

        foreach ($template->sections[0]['questions'] as $pertanyaan) {
            $isi[$pertanyaan['key']] = $pertanyaan['options'][0]['value'];
        }

        $this->formulir->save($jawaban, $isi);

        return $this->formulir->finalize($jawaban->refresh(), $this->dokterAnestesi);
    }

    /**
     * @return array<string, string>
     */
    private function jawabanAldrete(int $nilai): array
    {
        $pilihan = (string) $nilai;

        return [
            'aktivitas' => $pilihan, 'respirasi' => $pilihan, 'sirkulasi' => $pilihan,
            'kesadaran' => $pilihan, 'saturasi' => $pilihan,
        ];
    }

    private function operasi(?Registration $registrasi = null, string $kodeTindakan = 'OPR-APENDEKTOMI'): Operation
    {
        $registrasi ??= $this->daftarkan();

        return app(ClinicalRecordService::class)->recordOperation(
            $registrasi->id,
            $kodeTindakan,
            'dr. Bedah Uji, Sp.B',
            'umum',
            'OK-1',
            null,
            $this->dokterAnestesi,
        );
    }

    private function daftarkan(): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Anestesi '.$urut, 'sex' => 'L', 'birth_date' => '1985-05-05',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
        );
    }
}
