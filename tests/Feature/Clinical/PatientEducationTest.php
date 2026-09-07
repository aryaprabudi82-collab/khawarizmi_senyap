<?php

namespace Tests\Feature\Clinical;

use App\Modules\Catalog\Models\DocumentType;
use App\Modules\Catalog\Models\Payer;
use App\Modules\Clinical\Models\EducationSession;
use App\Modules\Clinical\Services\ClinicalException;
use App\Modules\Clinical\Services\MedicalRecordFileService;
use App\Modules\Clinical\Services\PatientEducationService;
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
 * Edukasi pasien & keluarga (domain M item Q).
 *
 * Yang paling perlu dikunci:
 *
 * 1. PERTANYAAN KEYAKINAN BOLEH KOSONG. Khanza mewajibkannya sebagai
 *    NOT NULL dengan kosakata "ujian atau kutukan" — yang dihasilkan
 *    hanya isian asal-asalan atau label yang dipaksakan.
 * 2. CARA DAN HAMBATAN BELAJAR ADALAH DAFTAR: nyeri DAN buta huruf
 *    adalah keadaan yang lazim.
 * 3. PENGULANGAN MENUNJUK APA YANG DIULANG.
 * 4. LAMA EDUKASI DIHITUNG, bukan diketik.
 * 5. FOTO BUKTI MEMAKAI MEKANISME BERKAS REKAM MEDIS item L.
 */
class PatientEducationTest extends TestCase
{
    use RefreshDatabase;

    private PatientEducationService $edukasi;

    private User $perawat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->edukasi = app(PatientEducationService::class);

        $this->perawat = User::query()->create([
            'username' => 'uji-edukasi', 'name' => 'Ns. Pemberi Edukasi',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    // ============================================== pertanyaan keyakinan

    #[Test]
    public function pertanyaan_keyakinan_boleh_dikosongkan(): void
    {
        $kunjungan = $this->daftarkan();

        // Khanza memasang ketiganya NOT NULL. Di sini pengkajian tanpa
        // satu pun jawaban keyakinan tetap sah — dan itu jauh lebih jujur
        // daripada label yang dipaksakan.
        $kajian = $this->edukasi->assessLearningNeeds($kunjungan->id, [
            'daily_language' => 'Bahasa Indonesia',
        ], $this->perawat);

        $this->assertNull($kajian->illness_belief);
        $this->assertNull($kajian->decision_maker);
        $this->assertNull($kajian->therapy_belief);
    }

    #[Test]
    public function pertanyaan_keyakinan_yang_belum_ditanyakan_bisa_disebutkan(): void
    {
        $kunjungan = $this->daftarkan();
        $kajian = $this->edukasi->assessLearningNeeds($kunjungan->id, [
            'illness_belief' => 'takdir',
        ], $this->perawat);

        // Disebutkan bagi yang ingin melengkapi, BUKAN sebagai syarat
        // menutup apa pun.
        $belum = $kajian->unansweredBeliefQuestions();

        $this->assertNotContains('keyakinan tentang penyakitnya', $belum);
        $this->assertContains('siapa yang mengambil keputusan', $belum);
        $this->assertContains('keyakinan terhadap terapi', $belum);
    }

    #[Test]
    public function kosakata_keyakinan_dilebarkan_dari_dua_pilihan_khanza(): void
    {
        $kunjungan = $this->daftarkan();

        // "Takdir yang diterima" dan "penyakit biasa yang bisa diobati"
        // tidak punya tempat di enum('Ujian/Cobaan','Kutukan','Lain-lain').
        $kajian = $this->edukasi->assessLearningNeeds($kunjungan->id, [
            'illness_belief' => 'penyakit-biasa',
            'therapy_belief' => 'yakin-jika-kontrol',
            'decision_maker' => 'keluarga-musyawarah',
        ], $this->perawat);

        $this->assertSame('penyakit-biasa', $kajian->illness_belief);
        $this->assertSame([], $kajian->unansweredBeliefQuestions());
    }

    #[Test]
    public function keyakinan_di_luar_kosakata_ditolak_tapi_pesannya_menyebut_boleh_kosong(): void
    {
        $kunjungan = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/boleh juga dikosongkan bila belum ditanyakan/');

        $this->edukasi->assessLearningNeeds($kunjungan->id, [
            'illness_belief' => 'entah-apa',
        ], $this->perawat);
    }

    // ============================================== daftar hambatan

    #[Test]
    public function hambatan_belajar_boleh_lebih_dari_satu(): void
    {
        $kunjungan = $this->daftarkan();

        // Pasien yang nyeri SEKALIGUS buta huruf adalah keadaan yang
        // lazim, bukan pengecualian — dan enum Khanza memaksa membuang
        // salah satunya.
        $kajian = $this->edukasi->assessLearningNeeds($kunjungan->id, [
            'learning_barriers' => ['nyeri', 'buta-huruf', 'kelelahan'],
            'learning_preferences' => ['diskusi', 'simulasi'],
        ], $this->perawat);

        $this->assertSame(['nyeri', 'buta-huruf', 'kelelahan'], $kajian->learning_barriers);
        $this->assertSame(['diskusi', 'simulasi'], $kajian->learning_preferences);
        $this->assertTrue($kajian->needsAdaptedEducation());
    }

    #[Test]
    public function tidak_ada_hambatan_tidak_dihitung_sebagai_hambatan(): void
    {
        $kunjungan = $this->daftarkan();
        $kajian = $this->edukasi->assessLearningNeeds($kunjungan->id, [
            'learning_barriers' => ['tidak-ada'],
        ], $this->perawat);

        $this->assertSame([], $kajian->actualBarriers());
        $this->assertFalse($kajian->needsAdaptedEducation());
    }

    #[Test]
    public function butuh_penerjemah_menuntut_penyesuaian_meski_tanpa_hambatan_lain(): void
    {
        $kunjungan = $this->daftarkan();
        $kajian = $this->edukasi->assessLearningNeeds($kunjungan->id, [
            'learning_barriers' => ['tidak-ada'],
            'needs_interpreter' => true,
            'interpreter_language' => 'Bahasa Jawa',
        ], $this->perawat);

        $this->assertTrue($kajian->needsAdaptedEducation());
    }

    #[Test]
    public function butuh_penerjemah_yang_belum_ditanyakan_bukan_berarti_tidak(): void
    {
        $kunjungan = $this->daftarkan();
        $kajian = $this->edukasi->assessLearningNeeds($kunjungan->id, [], $this->perawat);

        $this->assertNull($kajian->needs_interpreter);
        $this->assertFalse($kajian->needsAdaptedEducation());
    }

    #[Test]
    public function hambatan_di_luar_kosakata_ditolak(): void
    {
        $kunjungan = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/Hambatan belajar tidak dikenali: malas/');

        $this->edukasi->assessLearningNeeds($kunjungan->id, [
            'learning_barriers' => ['nyeri', 'malas'],
        ], $this->perawat);
    }

    #[Test]
    public function pengkajian_diperbarui_bukan_digandakan(): void
    {
        $kunjungan = $this->daftarkan();

        $pertama = $this->edukasi->assessLearningNeeds($kunjungan->id, [
            'daily_language' => 'Bahasa Indonesia',
        ], $this->perawat);
        $kedua = $this->edukasi->assessLearningNeeds($kunjungan->id, [
            'daily_language' => 'Bahasa Jawa',
        ], $this->perawat);

        $this->assertSame($pertama->id, $kedua->id);
        $this->assertSame('Bahasa Jawa', $kedua->daily_language);
    }

    // ============================================== pelaksanaan & pengulangan

    #[Test]
    public function edukasi_yang_belum_dimengerti_menunjuk_pengulangannya(): void
    {
        $kunjungan = $this->daftarkan();

        $awal = $this->beriEdukasi($kunjungan, ['topic' => 'Cara menyuntik insulin']);
        $this->edukasi->verify($awal, EducationSession::PERLU_RE_DEMONSTRASI, [
            'verification_note' => 'Pasien masih ragu menentukan dosis pada jarum.',
        ]);

        $this->assertTrue($awal->refresh()->isRepeatOutstanding());

        $ulang = $this->beriEdukasi($kunjungan, [
            'topic' => 'Cara menyuntik insulin',
            'repeats_session_id' => $awal->id,
        ]);

        // Khanza cuma punya penanda "Ulang" tanpa menyebut apa yang
        // diulang, jadi pertanyaan ini tidak bisa dijawab di sana.
        $this->assertSame($awal->id, $ulang->repeats_session_id);
        $this->assertFalse($awal->refresh()->isRepeatOutstanding());
    }

    #[Test]
    public function edukasi_yang_perlu_diulang_dan_belum_diulang_bisa_ditagih(): void
    {
        $kunjungan = $this->daftarkan();

        $gagal = $this->beriEdukasi($kunjungan, ['topic' => 'Perawatan luka di rumah']);
        $this->edukasi->verify($gagal, EducationSession::PERLU_RE_EDUKASI);

        $berhasil = $this->beriEdukasi($kunjungan, ['topic' => 'Tanda bahaya yang harus dilaporkan']);
        $this->edukasi->verify($berhasil, EducationSession::SUDAH_MENGERTI);

        $tertunggak = $this->edukasi->outstandingRepeats($kunjungan->id)->pluck('id')->all();

        $this->assertContains($gagal->id, $tertunggak);
        $this->assertNotContains($berhasil->id, $tertunggak);
    }

    #[Test]
    public function edukasi_yang_sudah_dimengerti_tidak_perlu_diulang(): void
    {
        $kunjungan = $this->daftarkan();
        $sesi = $this->beriEdukasi($kunjungan);
        $this->edukasi->verify($sesi, EducationSession::SUDAH_MENGERTI);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/sudah diverifikasi mengerti/');

        $this->beriEdukasi($kunjungan, ['repeats_session_id' => $sesi->id]);
    }

    #[Test]
    public function satu_edukasi_tidak_bisa_diulang_dua_kali(): void
    {
        $kunjungan = $this->daftarkan();
        $awal = $this->beriEdukasi($kunjungan);
        $this->edukasi->verify($awal, EducationSession::PERLU_RE_EDUKASI);

        $this->beriEdukasi($kunjungan, ['repeats_session_id' => $awal->id]);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/ulangi pengulangannya/');

        $this->beriEdukasi($kunjungan, ['repeats_session_id' => $awal->id]);
    }

    #[Test]
    public function pengulangan_edukasi_pasien_lain_ditolak(): void
    {
        $satu = $this->daftarkan();
        $lain = $this->daftarkan();

        $edukasiLain = $this->beriEdukasi($lain);
        $this->edukasi->verify($edukasiLain, EducationSession::PERLU_RE_EDUKASI);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/masih menggantung terbaca sudah selesai/');

        $this->beriEdukasi($satu, ['repeats_session_id' => $edukasiLain->id]);
    }

    #[Test]
    public function basis_data_menolak_edukasi_yang_mengulang_dirinya_sendiri(): void
    {
        $kunjungan = $this->daftarkan();
        $sesi = $this->beriEdukasi($kunjungan);

        $this->expectException(QueryException::class);

        EducationSession::query()->whereKey($sesi->id)->update(['repeats_session_id' => $sesi->id]);
    }

    #[Test]
    public function lama_edukasi_dihitung_bukan_diketik(): void
    {
        $kunjungan = $this->daftarkan();

        $sesi = $this->beriEdukasi($kunjungan, [
            'started_at' => now()->subMinutes(25),
            'ended_at' => now(),
        ]);

        // lama_edukasi varchar(10) Khanza diketik, jadi tidak bisa
        // diperiksa terhadap apa pun.
        $this->assertSame(25, $sesi->durationMinutes());
        $this->assertArrayNotHasKey('lama_edukasi', $sesi->getAttributes());
        $this->assertArrayNotHasKey('duration_minutes', $sesi->getAttributes());
    }

    #[Test]
    public function edukasi_tanpa_materi_ditolak(): void
    {
        $kunjungan = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/tidak bisa diulang maupun diverifikasi/');

        $this->beriEdukasi($kunjungan, ['material' => '   ']);
    }

    #[Test]
    public function metode_di_luar_kosakata_ditolak(): void
    {
        $kunjungan = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches("/'telepati' tidak dikenali/");

        $this->beriEdukasi($kunjungan, ['method' => 'telepati']);
    }

    #[Test]
    public function basis_data_menolak_urutan_waktu_yang_terbalik(): void
    {
        $kunjungan = $this->daftarkan();
        $sesi = $this->beriEdukasi($kunjungan);

        $this->expectException(QueryException::class);

        EducationSession::query()->whereKey($sesi->id)->update([
            'started_at' => now(), 'ended_at' => now()->subHour(),
        ]);
    }

    // ============================================== bukti pelaksanaan

    #[Test]
    public function bukti_edukasi_memakai_mekanisme_berkas_rekam_medis(): void
    {
        $kunjungan = $this->daftarkan();

        // bukti_pelaksanaan_informasi_edukasi Khanza cuma menyimpan jalur
        // foto tanpa pengunggah maupun waktunya — persoalan yang sudah
        // diselesaikan item L.
        $this->assertTrue(
            DocumentType::query()->where('code', 'BUKTI-EDUKASI')->where('is_active', true)->exists()
        );

        $berkas = app(MedicalRecordFileService::class)->attach($kunjungan->id, 'BUKTI-EDUKASI', [
            'original_filename' => 'edukasi-insulin.jpg',
            'stored_path' => '/berkas/edukasi-insulin.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 512000,
            'checksum_sha256' => hash('sha256', 'foto-edukasi'),
        ], $this->perawat);

        $this->assertSame('Ns. Pemberi Edukasi', $berkas->uploaded_by_name);
        $this->assertNotNull($berkas->uploaded_at);
        $this->assertSame(64, strlen($berkas->checksum_sha256));
    }

    // ---------------------------------------------------------------- fixture

    private function beriEdukasi(Registration $kunjungan, array $data = []): EducationSession
    {
        return $this->edukasi->educate($kunjungan->id, $data + [
            'topic' => 'Cara minum obat di rumah',
            'material' => 'Dijelaskan jadwal minum obat, cara menyimpan, dan tanda efek samping.',
            'given_to' => 'pasien-dan-keluarga',
            'method' => 'diskusi',
        ], $this->perawat);
    }

    private function daftarkan(): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Edukasi '.$urut, 'sex' => 'P', 'birth_date' => '1979-07-07',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
            practitionerId: Practitioner::query()->where('is_active', true)->value('id'),
        );
    }
}
