<?php

namespace Tests\Feature\Clinical;

use App\Modules\Catalog\Models\FormTemplate;
use App\Modules\Catalog\Services\CatalogException;
use App\Modules\Catalog\Services\FormTemplateService;
use App\Modules\Catalog\Models\Payer;
use App\Modules\Clinical\Models\FormResponse;
use App\Modules\Clinical\Services\ClinicalException;
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
 * Template & pengisian formulir asesmen/skrining (domain M item A).
 *
 * Yang paling perlu dikunci:
 *
 * 1. VERSI TEMPLATE DIBEKUKAN PADA JAWABANNYA. Merevisi template tidak
 *    boleh mengubah formulir yang sudah diisi — kalau berubah, isi rekam
 *    medis berubah tanpa ada yang menyentuhnya.
 * 2. SKOR DIHITUNG DARI VERSI YANG DIPAKAI, bukan versi terbaru. Ini
 *    kebalikan dari aturan durasi indikator mutu, dan bedanya disengaja.
 * 3. DRAF BUKAN REKAM MEDIS; hanya yang final dilaporkan.
 */
class FormTemplateTest extends TestCase
{
    use RefreshDatabase;

    private FormTemplateService $templates;
    private FormResponseService $formulir;
    private User $perawat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->templates = app(FormTemplateService::class);
        $this->formulir = app(FormResponseService::class);

        $this->perawat = User::query()->create([
            'username' => 'uji-formulir', 'name' => 'Perawat Uji',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    // -------------------------------------------------------------- template

    #[Test]
    public function template_baru_dibuat_sebagai_versi_satu(): void
    {
        $t = $this->buatSkriningTbc();

        $this->assertSame(1, $t->version);
        $this->assertTrue($t->is_active);
        $this->assertSame(FormTemplate::SKRINING, $t->category);
    }

    #[Test]
    public function kode_template_tidak_boleh_dipakai_dua_kali(): void
    {
        $this->buatSkriningTbc();

        $this->expectException(CatalogException::class);
        $this->expectExceptionMessage('terbitkan versi baru');

        $this->buatSkriningTbc();
    }

    #[Test]
    public function template_tanpa_bagian_pertanyaan_ditolak(): void
    {
        $this->expectException(CatalogException::class);
        $this->expectExceptionMessage('sekurang-kurangnya satu bagian');

        $this->templates->create([
            'code' => 'skrining-kosong', 'name' => 'Kosong',
            'category' => FormTemplate::SKRINING, 'sections' => [],
        ]);
    }

    /** Kunci ganda membuat jawaban saling menimpa diam-diam. */
    #[Test]
    public function kunci_pertanyaan_ganda_ditolak(): void
    {
        $this->expectException(CatalogException::class);
        $this->expectExceptionMessage('dipakai dua kali');

        $this->templates->create([
            'code' => 'skrining-ganda', 'name' => 'Ganda',
            'category' => FormTemplate::SKRINING,
            'sections' => [[
                'title' => 'Gejala',
                'questions' => [
                    ['key' => 'batuk', 'label' => 'Batuk', 'type' => 'boolean'],
                    ['key' => 'batuk', 'label' => 'Batuk lagi', 'type' => 'boolean'],
                ],
            ]],
        ]);
    }

    #[Test]
    public function revisi_membuat_versi_baru_dan_menonaktifkan_yang_lama(): void
    {
        $this->buatSkriningTbc();

        $v2 = $this->templates->revise('skrining-tbc', [
            'sections' => $this->bagianTbc(tambahDemam: true),
        ]);

        $this->assertSame(2, $v2->version);
        $this->assertTrue($v2->is_active);

        // Yang lama TIDAK dihapus — rekam medis yang menunjuknya tetap
        // punya pertanyaannya.
        $v1 = $this->templates->version('skrining-tbc', 1);
        $this->assertNotNull($v1);
        $this->assertFalse($v1->is_active);
        $this->assertCount(2, $this->templates->history('skrining-tbc'));
    }

    /** Dua versi aktif = dua petugas mengisi formulir berbeda untuk hal yang sama. */
    #[Test]
    public function basis_data_menolak_dua_versi_aktif_untuk_satu_kode(): void
    {
        $this->buatSkriningTbc();

        $this->expectException(QueryException::class);

        FormTemplate::query()->create([
            'code' => 'skrining-tbc', 'version' => 2, 'name' => 'Duplikat aktif',
            'category' => FormTemplate::SKRINING, 'sections' => $this->bagianTbc(), 'is_active' => true,
        ]);
    }

    // ------------------------------------------------------------- pengisian

    #[Test]
    public function formulir_dibuka_membekukan_versi_template_yang_dipakai(): void
    {
        $this->buatSkriningTbc();
        $registrasi = $this->daftarkan();

        $f = $this->formulir->open($registrasi->id, 'skrining-tbc', $this->perawat);

        $this->assertSame(1, $f->template_version);
        $this->assertSame(FormResponse::DRAF, $f->status);
        $this->assertSame('Skrining TBC', $f->template_name);
    }

    /** Membuka dua kali melanjutkan draf yang sama, bukan membuat baris kedua. */
    #[Test]
    public function membuka_formulir_yang_sama_dua_kali_melanjutkan_draf(): void
    {
        $this->buatSkriningTbc();
        $registrasi = $this->daftarkan();

        $pertama = $this->formulir->open($registrasi->id, 'skrining-tbc');
        $kedua = $this->formulir->open($registrasi->id, 'skrining-tbc');

        $this->assertSame($pertama->id, $kedua->id);
        $this->assertSame(1, FormResponse::query()->count());
    }

    #[Test]
    public function skor_dihitung_dari_bobot_pilihan_dan_ditafsirkan(): void
    {
        $this->buatSkriningTbc();
        $f = $this->formulir->open($this->daftarkan()->id, 'skrining-tbc');

        $terisi = $this->formulir->save($f, [
            'batuk_2minggu' => true,
            'berat_turun' => true,
            'kontak_erat' => false,
        ]);

        $this->assertSame(3, $terisi->score);
        $this->assertSame('tinggi', $terisi->risk_level);
        $this->assertStringContainsString('rujuk', $terisi->interpretation);
    }

    #[Test]
    public function skor_rendah_ditafsirkan_berbeda(): void
    {
        $this->buatSkriningTbc();
        $f = $this->formulir->open($this->daftarkan()->id, 'skrining-tbc');

        $terisi = $this->formulir->save($f, ['batuk_2minggu' => false, 'berat_turun' => false, 'kontak_erat' => false]);

        $this->assertSame(0, $terisi->score);
        $this->assertSame('rendah', $terisi->risk_level);
    }

    /**
     * ATURAN KETIGA (jawaban asing): formulir yang dibuka sebelum
     * templatenya berubah harus ketahuan, bukan disimpan diam-diam.
     */
    #[Test]
    public function jawaban_di_luar_pertanyaan_template_ditolak(): void
    {
        $this->buatSkriningTbc();
        $f = $this->formulir->open($this->daftarkan()->id, 'skrining-tbc');

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessage('tidak dikenali template ini');

        $this->formulir->save($f, ['batuk_2minggu' => true, 'demam' => true]);
    }

    /**
     * INTI ITEM INI: merevisi template tidak mengubah formulir yang sudah
     * diisi, dan skornya tetap dihitung dengan aturan versi lamanya.
     */
    #[Test]
    public function revisi_template_tidak_mengubah_formulir_yang_sudah_diisi(): void
    {
        $this->buatSkriningTbc();
        $f = $this->formulir->open($this->daftarkan()->id, 'skrining-tbc');
        $this->formulir->save($f, ['batuk_2minggu' => true, 'berat_turun' => true, 'kontak_erat' => false]);
        $this->formulir->finalize($f->refresh(), $this->perawat);

        $sebelum = $f->refresh()->only(['template_version', 'score', 'risk_level']);

        // Pedoman direvisi: ambang risiko tinggi dinaikkan.
        $this->templates->revise('skrining-tbc', [
            'sections' => $this->bagianTbc(tambahDemam: true),
            'scoring' => ['bands' => [
                ['min' => 0, 'max' => 3, 'risk_level' => 'rendah', 'interpretation' => 'Tidak perlu rujuk.'],
                ['min' => 4, 'max' => 99, 'risk_level' => 'tinggi', 'interpretation' => 'Segera rujuk ke poli paru.'],
            ]],
        ]);

        $this->assertSame($sebelum, $f->refresh()->only(['template_version', 'score', 'risk_level']));
        $this->assertSame(1, $f->refresh()->template_version);
        $this->assertSame('tinggi', $f->refresh()->risk_level);
    }

    /** Formulir baru sesudah revisi memakai versi baru berikut ambang barunya. */
    #[Test]
    public function formulir_baru_sesudah_revisi_memakai_versi_dan_ambang_baru(): void
    {
        $this->buatSkriningTbc();

        $this->templates->revise('skrining-tbc', [
            'sections' => $this->bagianTbc(tambahDemam: true),
            'scoring' => ['bands' => [
                ['min' => 0, 'max' => 3, 'risk_level' => 'rendah', 'interpretation' => 'Tidak perlu rujuk.'],
                ['min' => 4, 'max' => 99, 'risk_level' => 'tinggi', 'interpretation' => 'Segera rujuk ke poli paru.'],
            ]],
        ]);

        $f = $this->formulir->open($this->daftarkan()->id, 'skrining-tbc');
        $terisi = $this->formulir->save($f, ['batuk_2minggu' => true, 'berat_turun' => true, 'kontak_erat' => false]);

        $this->assertSame(2, $terisi->template_version);
        // Skor 3 sekarang masuk kategori rendah, bukan tinggi.
        $this->assertSame(3, $terisi->score);
        $this->assertSame('rendah', $terisi->risk_level);
    }

    // ------------------------------------------------------------ finalisasi

    #[Test]
    public function pertanyaan_wajib_yang_belum_dijawab_menahan_finalisasi(): void
    {
        $this->buatSkriningTbc();
        $f = $this->formulir->open($this->daftarkan()->id, 'skrining-tbc');
        $this->formulir->save($f, ['berat_turun' => true]);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessage('Pertanyaan wajib belum dijawab');

        $this->formulir->finalize($f->refresh(), $this->perawat);
    }

    /**
     * Nol dan false adalah jawaban yang SAH. Memakai empty() akan menolak
     * skor nyeri 0 — jawaban yang justru paling sering benar.
     */
    #[Test]
    public function jawaban_false_dihitung_sudah_dijawab(): void
    {
        $this->buatSkriningTbc();
        $f = $this->formulir->open($this->daftarkan()->id, 'skrining-tbc');
        $this->formulir->save($f, ['batuk_2minggu' => false, 'berat_turun' => false, 'kontak_erat' => false]);

        $final = $this->formulir->finalize($f->refresh(), $this->perawat);

        $this->assertTrue($final->isFinal());
        $this->assertNotNull($final->finalized_at);
    }

    #[Test]
    public function formulir_final_tidak_bisa_diubah(): void
    {
        $this->buatSkriningTbc();
        $f = $this->formulir->open($this->daftarkan()->id, 'skrining-tbc');
        $this->formulir->save($f, ['batuk_2minggu' => true, 'berat_turun' => false, 'kontak_erat' => false]);
        $this->formulir->finalize($f->refresh(), $this->perawat);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessage('tidak bisa diubah');

        $this->formulir->save($f->refresh(), ['batuk_2minggu' => false, 'berat_turun' => false, 'kontak_erat' => false]);
    }

    /** Draf bukan rekam medis: tidak ikut daftar yang final. */
    #[Test]
    public function draf_tidak_ikut_daftar_formulir_final(): void
    {
        $this->buatSkriningTbc();
        $registrasi = $this->daftarkan();
        $f = $this->formulir->open($registrasi->id, 'skrining-tbc');
        $this->formulir->save($f, ['batuk_2minggu' => true, 'berat_turun' => false, 'kontak_erat' => false]);

        $this->assertCount(0, $this->formulir->finalizedFor($registrasi->id));

        $this->formulir->finalize($f->refresh(), $this->perawat);

        $this->assertCount(1, $this->formulir->finalizedFor($registrasi->id));
    }

    #[Test]
    public function basis_data_menolak_status_final_tanpa_waktu_finalisasi(): void
    {
        $this->buatSkriningTbc();
        $f = $this->formulir->open($this->daftarkan()->id, 'skrining-tbc');

        $this->expectException(QueryException::class);

        $f->update(['status' => FormResponse::FINAL]);
    }

    // ------------------------------------------------------ template berulang

    /**
     * KOREKSI TERHADAP ATURAN ITEM A. "Satu formulir per kunjungan" benar
     * untuk asesmen, tapi Early Warning Score dinilai setiap beberapa jam
     * — itulah gunanya: menangkap perburukan yang tidak terlihat pada satu
     * titik waktu. Aturan lama akan menahan penilaian kedua, dan pasien
     * yang memburuk pukul tiga pagi tidak akan punya barisnya.
     */
    #[Test]
    public function instrumen_berulang_membuka_lembar_baru_setiap_kali(): void
    {
        $this->buatEws();
        $registrasi = $this->daftarkan();

        $pertama = $this->formulir->open($registrasi->id, 'ews-dewasa', $this->perawat);
        $kedua = $this->formulir->open($registrasi->id, 'ews-dewasa', $this->perawat);

        $this->assertNotSame($pertama->id, $kedua->id);
        $this->assertSame(2, FormResponse::query()->where('template_code', 'ews-dewasa')->count());
    }

    /** Sifat berulang dibekukan di jawabannya, bukan dibaca dari template. */
    #[Test]
    public function sifat_berulang_dibekukan_pada_jawabannya(): void
    {
        $this->buatEws();
        $f = $this->formulir->open($this->daftarkan()->id, 'ews-dewasa');

        $this->assertTrue($f->is_repeatable);

        // Template diubah jadi sekali-isi; jawaban lama tidak ikut berubah
        // dan tidak mendadak melanggar aturan yang belum berlaku saat ia
        // ditulis.
        $this->templates->revise('ews-dewasa', ['is_repeatable' => false]);

        $this->assertTrue($f->refresh()->is_repeatable);
    }

    /** Asesmen biasa TETAP satu per kunjungan — aturan item A tidak luntur. */
    #[Test]
    public function template_biasa_tetap_satu_per_kunjungan(): void
    {
        $this->buatSkriningTbc();
        $registrasi = $this->daftarkan();

        $pertama = $this->formulir->open($registrasi->id, 'skrining-tbc');
        $kedua = $this->formulir->open($registrasi->id, 'skrining-tbc');

        $this->assertSame($pertama->id, $kedua->id);
    }

    #[Test]
    public function skor_ews_dinilai_per_penilaian_bukan_diakumulasi(): void
    {
        $this->buatEws();
        $registrasi = $this->daftarkan();

        $pagi = $this->formulir->open($registrasi->id, 'ews-dewasa');
        $this->formulir->save($pagi, ['laju_respirasi' => '12-20', 'kesadaran' => 'sadar']);

        $malam = $this->formulir->open($registrasi->id, 'ews-dewasa');
        $this->formulir->save($malam, ['laju_respirasi' => '25-34', 'kesadaran' => 'nyeri-verbal']);

        $this->assertSame(0, $pagi->refresh()->score);
        $this->assertSame('rendah', $pagi->refresh()->risk_level);

        // Penilaian malam berdiri sendiri — perburukan terlihat karena
        // keduanya tercatat terpisah, bukan tertimpa.
        $this->assertSame(5, $malam->refresh()->score);
        $this->assertSame('tinggi', $malam->refresh()->risk_level);
    }

    // ---------------------------------------------------------------- daftar

    /**
     * "Belum diskrining" dan "sudah diskrining, hasilnya negatif" adalah
     * dua pernyataan berbeda — daftar ini yang membuat bedanya terlihat.
     */
    #[Test]
    public function daftar_periksa_membedakan_belum_diisi_dari_sudah_diisi(): void
    {
        $this->buatSkriningTbc();
        $this->templates->create([
            'code' => 'skrining-anemia', 'name' => 'Skrining Anemia',
            'category' => FormTemplate::SKRINING,
            'sections' => [['title' => 'Gejala', 'questions' => [
                ['key' => 'pucat', 'label' => 'Tampak pucat', 'type' => 'boolean'],
            ]]],
        ]);

        $registrasi = $this->daftarkan();
        $f = $this->formulir->open($registrasi->id, 'skrining-tbc');
        $this->formulir->save($f, ['batuk_2minggu' => false, 'berat_turun' => false, 'kontak_erat' => false]);
        $this->formulir->finalize($f->refresh(), $this->perawat);

        $daftar = $this->formulir->checklistFor($registrasi->id, FormTemplate::SKRINING)->keyBy('code');

        $this->assertSame(FormResponse::FINAL, $daftar['skrining-tbc']->status);
        $this->assertSame('rendah', $daftar['skrining-tbc']->risk_level);
        // Yang belum diisi tetap muncul, dengan status null — bukan hilang.
        $this->assertNull($daftar['skrining-anemia']->status);
    }

    #[Test]
    public function riwayat_skrining_pasien_terkumpul_lintas_kunjungan(): void
    {
        $this->buatSkriningTbc();
        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Skrining Berulang', 'sex' => 'L', 'birth_date' => '1970-01-01',
        ]);

        foreach ([2, 1] as $mundur) {
            $registrasi = $this->daftarkan($pasien, $mundur);
            $f = $this->formulir->open($registrasi->id, 'skrining-tbc');
            $this->formulir->save($f, ['batuk_2minggu' => true, 'berat_turun' => false, 'kontak_erat' => false]);
            $this->formulir->finalize($f->refresh(), $this->perawat);
        }

        $this->assertCount(2, $this->formulir->historyFor($pasien->id, 'skrining-tbc'));
    }

    // ------------------------------------------------------------------ bantu

    private function buatSkriningTbc(): FormTemplate
    {
        return $this->templates->create([
            'code' => 'skrining-tbc',
            'name' => 'Skrining TBC',
            'category' => FormTemplate::SKRINING,
            'sections' => $this->bagianTbc(),
            'scoring' => ['bands' => [
                ['min' => 0, 'max' => 1, 'risk_level' => 'rendah', 'interpretation' => 'Tidak perlu rujuk.'],
                ['min' => 2, 'max' => 99, 'risk_level' => 'tinggi', 'interpretation' => 'Segera rujuk ke poli paru.'],
            ]],
            'note' => 'Mengikuti pedoman skrining TBC Kemenkes.',
        ], $this->perawat->id);
    }

    /**
     * Early Warning Score dewasa: instrumen pemantauan yang memang dinilai
     * berulang. Bandnya mengikuti bentuk pemantauan_pews_dewasa Khanza —
     * parameter berkategori, masing-masing berbobot skor.
     */
    private function buatEws(): FormTemplate
    {
        return $this->templates->create([
            'code' => 'ews-dewasa',
            'name' => 'Early Warning Score Dewasa',
            'category' => FormTemplate::PENGKAJIAN_LANJUTAN,
            'is_repeatable' => true,
            'sections' => [['title' => 'Parameter fisiologis', 'questions' => [
                ['key' => 'laju_respirasi', 'label' => 'Laju respirasi', 'type' => 'choice', 'options' => [
                    ['value' => '12-20', 'label' => '12 - 20', 'score' => 0],
                    ['value' => '21-24', 'label' => '21 - 24', 'score' => 2],
                    ['value' => '25-34', 'label' => '25 - 34', 'score' => 3],
                ]],
                ['key' => 'kesadaran', 'label' => 'Tingkat kesadaran', 'type' => 'choice', 'options' => [
                    ['value' => 'sadar', 'label' => 'Sadar', 'score' => 0],
                    ['value' => 'nyeri-verbal', 'label' => 'Nyeri/Verbal', 'score' => 2],
                    ['value' => 'unrespon', 'label' => 'Unrespon', 'score' => 3],
                ]],
            ]]],
            'scoring' => ['bands' => [
                ['min' => 0, 'max' => 2, 'risk_level' => 'rendah', 'interpretation' => 'Pemantauan rutin.'],
                ['min' => 3, 'max' => 4, 'risk_level' => 'sedang', 'interpretation' => 'Tingkatkan frekuensi pemantauan.'],
                ['min' => 5, 'max' => 99, 'risk_level' => 'tinggi', 'interpretation' => 'Aktifkan tim reaksi cepat.'],
            ]],
            'note' => 'Ambang mengikuti pedoman EWS dewasa yang berlaku di RSP UI.',
        ], $this->perawat->id);
    }

    /** @return array<int, array<string, mixed>> */
    private function bagianTbc(bool $tambahDemam = false): array
    {
        $pertanyaan = [
            ['key' => 'batuk_2minggu', 'label' => 'Batuk lebih dari 2 minggu', 'type' => 'boolean', 'score' => 2, 'required' => true],
            ['key' => 'berat_turun', 'label' => 'Berat badan turun', 'type' => 'boolean', 'score' => 1, 'required' => true],
            ['key' => 'kontak_erat', 'label' => 'Kontak erat penderita TBC', 'type' => 'boolean', 'score' => 1, 'required' => true],
        ];

        if ($tambahDemam) {
            $pertanyaan[] = ['key' => 'demam_malam', 'label' => 'Demam pada malam hari', 'type' => 'boolean', 'score' => 1];
        }

        return [['title' => 'Gejala dan faktor risiko', 'questions' => $pertanyaan]];
    }

    private function daftarkan(?object $pasien = null, int $mundurHari = 0): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien ??= app(PatientRegistry::class)->register([
            'name' => 'Pasien Formulir ' . $urut, 'sex' => 'P', 'birth_date' => '1990-02-02',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
            serviceDate: now()->subDays($mundurHari),
        );
    }
}
