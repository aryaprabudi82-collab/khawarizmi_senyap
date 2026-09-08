<?php

namespace Tests\Feature\Correspondence;

use App\Modules\Correspondence\Models\ConsentTemplate;
use App\Modules\Correspondence\Models\PatientConsent;
use App\Modules\Correspondence\Services\ConsentService;
use App\Modules\Correspondence\Services\CorrespondenceException;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Informed consent yang bisa dibuktikan (domain P item A).
 *
 * Yang dikunci:
 *
 * 1. BUTIR PENJELASAN DISALIN, BUKAN DIRUJUK — revisi template tidak boleh
 *    mengubah bunyi persetujuan yang sudah ditandatangani.
 * 2. BELUM DIJELASKAN ADALAH NULL, bukan false, dan bukan pula sama dengan
 *    "sudah dijelaskan tapi belum dipahami".
 * 3. ATURANNYA TIDAK SIMETRIS — setuju ditahan bila ada butir wajib yang
 *    belum dijelaskan; menolak tidak pernah ditahan.
 * 4. PERWAKILAN TANPA ALASAN DITOLAK, di service DAN di basis data.
 */
class ConsentEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private ConsentService $consents;

    private User $dokter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->consents = app(ConsentService::class);

        $this->dokter = User::query()->create([
            'username' => 'uji-dokter-consent', 'name' => 'Dokter Uji Consent',
            'password' => 'password', 'is_active' => true,
        ]);
        $this->dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());
    }

    // ======================================================== template

    #[Test]
    public function butir_template_disalin_ke_persetujuan_bukan_dirujuk(): void
    {
        $template = $this->buatTemplate();

        $persetujuan = $this->consents->issueFromTemplate($template, [
            'patient_name' => 'Budi Santoso',
            'procedure_description' => 'Apendektomi',
        ], $this->dokter->id);

        // Template direvisi SESUDAH persetujuan ditandatangani.
        $template->items()->where('label', 'Risiko')->update(['body' => 'Kalimat risiko yang sudah direvisi']);

        $persetujuan->load('items');
        $risiko = $persetujuan->items->firstWhere('label', 'Risiko');

        // Dokumen hukum tidak boleh berubah sendiri tanpa ada yang
        // menyentuhnya.
        $this->assertSame('Perdarahan dan infeksi luka operasi', $risiko->body);
    }

    #[Test]
    public function versi_template_ikut_dibekukan_pada_persetujuan(): void
    {
        $template = $this->buatTemplate();

        $persetujuan = $this->consents->issueFromTemplate($template, [
            'patient_name' => 'Budi Santoso', 'procedure_description' => 'Apendektomi',
        ], $this->dokter->id);

        // Pertanyaan "versi mana yang ditandatangani" harus tetap punya
        // jawaban setelah templatenya direvisi berkali-kali.
        $this->assertSame($template->code, $persetujuan->template_code);
        $this->assertSame(1, $persetujuan->template_version);
        $this->assertSame($template->name, $persetujuan->template_name);
    }

    #[Test]
    public function template_tanpa_butir_ditolak(): void
    {
        $kosong = ConsentTemplate::query()->create([
            'code' => 'template-kosong', 'version' => 1, 'name' => 'Template Kosong',
            'consent_type' => 'tindakan', 'is_active' => true,
        ]);

        // Template tanpa butir menghasilkan persetujuan yang tidak bisa
        // membuktikan apa pun — persis keadaan yang hendak diperbaiki.
        $this->expectException(CorrespondenceException::class);
        $this->expectExceptionMessageMatches('/belum berisi butir/');

        $this->consents->issueFromTemplate($kosong, [
            'patient_name' => 'Budi', 'procedure_description' => 'Tindakan',
        ], $this->dokter->id);
    }

    #[Test]
    public function template_nonaktif_tidak_bisa_dipakai(): void
    {
        $template = $this->buatTemplate();
        $template->update(['is_active' => false]);

        $this->expectException(CorrespondenceException::class);
        $this->expectExceptionMessageMatches('/tidak aktif/');

        $this->consents->issueFromTemplate($template, [
            'patient_name' => 'Budi', 'procedure_description' => 'Apendektomi',
        ], $this->dokter->id);
    }

    // ==================================================== tiga keadaan

    #[Test]
    public function persetujuan_baru_lahir_belum_dikonfirmasi_dan_seluruh_butirnya_null(): void
    {
        $persetujuan = $this->consents->issueFromTemplate($this->buatTemplate(), [
            'patient_name' => 'Budi Santoso', 'procedure_description' => 'Apendektomi',
        ], $this->dokter->id);

        // Formulir yang sudah dibuat tapi belum ditandatangani BUKAN
        // penolakan — menghitungnya sebagai penolakan akan melaporkan
        // pasien menolak padahal ia belum ditanya.
        $this->assertSame(PatientConsent::KEPUTUSAN_BELUM, $persetujuan->decision);
        $this->assertCount(3, $persetujuan->items);

        foreach ($persetujuan->items as $butir) {
            $this->assertNull($butir->confirmed);
            $this->assertTrue($butir->belumDitanyakan());
        }
    }

    #[Test]
    public function belum_dijelaskan_berbeda_dari_sudah_dijelaskan_tapi_belum_dipahami(): void
    {
        $persetujuan = $this->consents->issueFromTemplate($this->buatTemplate(), [
            'patient_name' => 'Budi Santoso', 'procedure_description' => 'Apendektomi',
        ], $this->dokter->id);

        $risiko = $persetujuan->items->firstWhere('label', 'Risiko');
        $this->consents->confirmItem($risiko, false, 'Pasien belum paham arti perdarahan mayor');

        $risiko->refresh();

        // False bukan null: ada jejak bahwa pasien menyatakan tidak paham,
        // dan jejak itulah yang hilang kalau keduanya disamakan.
        $this->assertFalse($risiko->confirmed);
        $this->assertFalse($risiko->belumDitanyakan());
        $this->assertSame('Pasien belum paham arti perdarahan mayor', $risiko->confirmation_note);
    }

    #[Test]
    public function butir_yang_dinyatakan_belum_dipahami_wajib_berketerangan(): void
    {
        $persetujuan = $this->consents->issueFromTemplate($this->buatTemplate(), [
            'patient_name' => 'Budi Santoso', 'procedure_description' => 'Apendektomi',
        ], $this->dokter->id);

        // Catatan "ada yang belum dipahami" tanpa menyebut apanya cuma
        // memberi tahu ada masalah, tanpa memberi tahu masalahnya.
        $this->expectException(CorrespondenceException::class);
        $this->expectExceptionMessageMatches('/apa yang belum dipahami/');

        $this->consents->confirmItem($persetujuan->items->first(), false, null);
    }

    // ============================================ ketaksimetrisan aturan

    #[Test]
    public function setuju_ditahan_selama_ada_butir_wajib_yang_belum_dijelaskan(): void
    {
        $persetujuan = $this->consents->issueFromTemplate($this->buatTemplate(), [
            'patient_name' => 'Budi Santoso', 'procedure_description' => 'Apendektomi',
        ], $this->dokter->id);

        $this->consents->confirmItem($persetujuan->items->firstWhere('label', 'Diagnosis'), true);

        // Orang tidak bisa menyetujui apa yang belum pernah disampaikan
        // kepadanya. Pesannya menyebut butir mana supaya petugas tahu apa
        // yang harus dikerjakan, bukan sekadar ditolak.
        $this->expectException(CorrespondenceException::class);
        $this->expectExceptionMessageMatches('/Tindakan, Risiko/');

        $this->consents->decide($persetujuan, PatientConsent::KEPUTUSAN_SETUJU);
    }

    #[Test]
    public function menolak_tidak_pernah_ditahan_meski_belum_satu_pun_dijelaskan(): void
    {
        $persetujuan = $this->consents->issueFromTemplate($this->buatTemplate(), [
            'patient_name' => 'Budi Santoso', 'procedure_description' => 'Apendektomi',
        ], $this->dokter->id);

        /*
         * Pasien berhak menghentikan penjelasan di tengah jalan. Memaksa
         * petugas mengisi seluruh butir dulu tidak membuat penjelasannya jadi
         * ada — ia hanya melahirkan konfirmasi karangan demi bisa menyimpan
         * formulir.
         */
        $hasil = $this->consents->decide($persetujuan, PatientConsent::KEPUTUSAN_MENOLAK);

        $this->assertSame(PatientConsent::KEPUTUSAN_MENOLAK, $hasil->decision);
        $this->assertCount(3, $hasil->load('items')->butirBelumDijelaskan());
    }

    #[Test]
    public function butir_yang_belum_dipahami_tidak_menahan_persetujuan(): void
    {
        $persetujuan = $this->consents->issueFromTemplate($this->buatTemplate(), [
            'patient_name' => 'Budi Santoso', 'procedure_description' => 'Apendektomi',
        ], $this->dokter->id);

        foreach ($persetujuan->items as $butir) {
            $this->consents->confirmItem($butir, $butir->label === 'Risiko' ? false : true,
                $butir->label === 'Risiko' ? 'Pasien minta dijelaskan ulang oleh keluarganya' : null);
        }

        /*
         * Menahannya justru mendorong petugas mengubah tandanya jadi "paham"
         * supaya formulirnya bisa disimpan — dan jejak satu-satunya bahwa ada
         * yang tidak dipahami akan hilang.
         */
        $hasil = $this->consents->decide($persetujuan, PatientConsent::KEPUTUSAN_SETUJU);

        $this->assertSame(PatientConsent::KEPUTUSAN_SETUJU, $hasil->decision);
    }

    #[Test]
    public function butir_tidak_wajib_tidak_menahan_persetujuan(): void
    {
        $template = $this->buatTemplate();
        $template->items()->create([
            'position' => 4, 'label' => 'Lain-lain', 'body' => 'Hal lain yang perlu diketahui',
            'is_required' => false,
        ]);

        $persetujuan = $this->consents->issueFromTemplate($template, [
            'patient_name' => 'Budi Santoso', 'procedure_description' => 'Apendektomi',
        ], $this->dokter->id);

        foreach ($persetujuan->items->where('is_required', true) as $butir) {
            $this->consents->confirmItem($butir, true);
        }

        $hasil = $this->consents->decide($persetujuan, PatientConsent::KEPUTUSAN_SETUJU);

        $this->assertSame(PatientConsent::KEPUTUSAN_SETUJU, $hasil->decision);
    }

    #[Test]
    public function butir_tidak_bisa_diubah_setelah_keputusan_direkam(): void
    {
        $persetujuan = $this->consents->issueFromTemplate($this->buatTemplate(), [
            'patient_name' => 'Budi Santoso', 'procedure_description' => 'Apendektomi',
        ], $this->dokter->id);

        $this->consents->decide($persetujuan, PatientConsent::KEPUTUSAN_MENOLAK);

        // Mengubah butir sesudah keputusan berarti mengubah dasar keputusan
        // yang sudah diambil, tanpa jejak.
        $this->expectException(CorrespondenceException::class);
        $this->expectExceptionMessageMatches('/sudah diputuskan/');

        $this->consents->confirmItem($persetujuan->items->first()->refresh(), true);
    }

    #[Test]
    public function keputusan_tidak_bisa_direkam_dua_kali(): void
    {
        $persetujuan = $this->consents->issueFromTemplate($this->buatTemplate(), [
            'patient_name' => 'Budi Santoso', 'procedure_description' => 'Apendektomi',
        ], $this->dokter->id);

        $this->consents->decide($persetujuan, PatientConsent::KEPUTUSAN_MENOLAK);

        $this->expectException(CorrespondenceException::class);
        $this->expectExceptionMessageMatches('/buat dokumen baru/');

        $this->consents->decide($persetujuan->refresh(), PatientConsent::KEPUTUSAN_SETUJU);
    }

    // =================================================== penanda tangan

    #[Test]
    public function perwakilan_tanpa_alasan_ditolak(): void
    {
        // Permenkes 290/2008 hanya membolehkan keluarga terdekat memberi
        // persetujuan bila pasien tidak kompeten — tanpa alasannya, tidak ada
        // yang bisa menilai apakah perwakilannya sah.
        $this->expectException(CorrespondenceException::class);
        $this->expectExceptionMessageMatches('/alasan pasien tidak menandatangani sendiri/');

        $this->consents->issue([
            'consent_type' => 'umum', 'patient_name' => 'Budi Santoso',
            'procedure_description' => 'Persetujuan umum', 'decision' => 'setuju',
            'signer_name' => 'Siti', 'signer_relationship' => 'istri',
        ], $this->dokter->id);
    }

    #[Test]
    public function pasien_yang_tanda_tangan_sendiri_tidak_boleh_punya_alasan_perwakilan(): void
    {
        // Alasan perwakilan pada penanda tangan diri sendiri adalah tanda
        // salah isi, dan membiarkannya membuat kolom itu tidak bisa dipercaya
        // sebagai penanda "ini diwakilkan".
        $this->expectException(CorrespondenceException::class);
        $this->expectExceptionMessageMatches('/menandatangani sendiri/');

        $this->consents->issue([
            'consent_type' => 'umum', 'patient_name' => 'Budi Santoso',
            'procedure_description' => 'Persetujuan umum', 'decision' => 'setuju',
            'signer_name' => 'Budi Santoso', 'signer_relationship' => 'diri-sendiri',
            'delegation_reason' => 'Pasien tidak sadar',
        ], $this->dokter->id);
    }

    #[Test]
    public function perwakilan_beralasan_diterima_dan_tercatat_lengkap(): void
    {
        $persetujuan = $this->consents->issue([
            'consent_type' => 'umum', 'patient_name' => 'Budi Santoso',
            'procedure_description' => 'Persetujuan umum', 'decision' => 'setuju',
            'signer_name' => 'Siti Aminah', 'signer_relationship' => 'istri',
            'signer_id_number' => '3271010101800001',
            'delegation_reason' => 'Pasien tidak sadar sejak tiba di IGD',
        ], $this->dokter->id);

        $this->assertFalse($persetujuan->ditandatanganiSendiri());
        $this->assertSame('Siti Aminah', $persetujuan->signer_name);
        $this->assertSame('3271010101800001', $persetujuan->signer_id_number);
    }

    #[Test]
    public function basis_data_menolak_perwakilan_tanpa_alasan_meski_service_dilewati(): void
    {
        /*
         * Service bukan satu-satunya pintu ke tabel ini. Persetujuan yang
         * masuk lewat pintu lain tetap harus bisa menjelaskan kewenangan
         * penanda tangannya.
         */
        $this->expectException(QueryException::class);

        DB::table('correspondence.patient_consents')->insert([
            'consent_number' => 'PST-UJI-LANGSUNG', 'consent_type' => 'umum',
            'patient_name' => 'Budi', 'procedure_description' => 'Lewat pintu belakang',
            'decision' => 'setuju', 'signed_at' => now(), 'status' => 'aktif',
            'signer_name' => 'Siti', 'signer_relationship' => 'istri',
            'delegation_reason' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    #[Test]
    public function hubungan_penanda_tangan_di_luar_daftar_ditolak(): void
    {
        $this->expectException(CorrespondenceException::class);
        $this->expectExceptionMessageMatches('/tidak dikenal/');

        $this->consents->issue([
            'consent_type' => 'umum', 'patient_name' => 'Budi',
            'procedure_description' => 'Persetujuan umum', 'decision' => 'setuju',
            'signer_relationship' => 'tetangga', 'delegation_reason' => 'Keluarga jauh',
        ], $this->dokter->id);
    }

    // ================================================ penolakan anjuran

    #[Test]
    public function penolakan_anjuran_medis_wajib_mencatat_akibat_yang_dijelaskan(): void
    {
        /*
         * Cerminan dari aturan persetujuan: menolak anjuran medis tanpa diberi
         * tahu akibatnya bukan penolakan yang sah, sama seperti menyetujui
         * tanpa diberi tahu risikonya bukan persetujuan yang sah.
         */
        $this->expectException(CorrespondenceException::class);
        $this->expectExceptionMessageMatches('/akibat yang sudah dijelaskan/');

        $this->consents->issue([
            'consent_type' => 'penolakan-anjuran-medis', 'patient_name' => 'Budi',
            'procedure_description' => 'Menolak rawat inap', 'decision' => 'menolak',
        ], $this->dokter->id);
    }

    #[Test]
    public function penolakan_anjuran_medis_dengan_akibat_yang_dijelaskan_diterima(): void
    {
        $persetujuan = $this->consents->issue([
            'consent_type' => 'penolakan-anjuran-medis', 'patient_name' => 'Budi',
            'procedure_description' => 'Menolak rawat inap',
            'decision' => 'menolak',
            'refusal_risk_explained' => 'Risiko perburukan dan kegawatan di rumah sudah dijelaskan',
        ], $this->dokter->id);

        $this->assertSame('menolak', $persetujuan->decision);
        $this->assertNotEmpty($persetujuan->refusal_risk_explained);
    }

    #[Test]
    public function daftar_alasan_penolakan_sengaja_lahir_kosong(): void
    {
        /*
         * Kosakata alasan menolak anjuran medis adalah diskresi RSP UI.
         * Garis yang sama dipakai sepanjang proyek: daftar yang ditetapkan di
         * luar rumah sakit boleh disalin (register TB, ragam disabilitas),
         * diskresi rumah sakit tidak boleh ditebak (jenjang jabatan, kriteria
         * triase). Tabel kosong itu jujur; tabel berisi lima alasan karangan
         * melahirkan statistik resmi tentang kategori yang tidak pernah
         * disepakati siapa pun.
         */
        $this->assertSame(0, DB::table('correspondence.medical_advice_refusal_reasons')->count());
    }

    // -------------------------------------------------------- fixture

    private function buatTemplate(): ConsentTemplate
    {
        $template = ConsentTemplate::query()->create([
            'code' => 'persetujuan-apendektomi', 'version' => 1,
            'name' => 'Persetujuan Apendektomi', 'consent_type' => 'tindakan',
            'estimated_cost' => 12500000, 'is_active' => true,
        ]);

        foreach ([
            ['Diagnosis', 'Apendisitis akut'],
            ['Tindakan', 'Apendektomi terbuka dengan anestesi umum'],
            ['Risiko', 'Perdarahan dan infeksi luka operasi'],
        ] as $urut => [$label, $isi]) {
            $template->items()->create([
                'position' => $urut + 1, 'label' => $label, 'body' => $isi, 'is_required' => true,
            ]);
        }

        return $template->load('items');
    }
}
