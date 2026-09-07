<?php

namespace Tests\Feature\Clinical;

use App\Modules\Catalog\Models\DocumentType;
use App\Modules\Catalog\Models\Payer;
use App\Modules\Clinical\Models\MedicalRecordDocument;
use App\Modules\Clinical\Models\RecordRetention;
use App\Modules\Clinical\Services\ClinicalException;
use App\Modules\Clinical\Services\MedicalRecordFileService;
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
 * Berkas digital & retensi rekam medis (domain M item L).
 *
 * Yang paling perlu dikunci:
 *
 * 1. TIAP BERKAS PUNYA PENGUNGGAH, WAKTU, DAN SIDIK — Khanza hanya
 *    menyimpan no_rawat, kode, dan jalur berkas.
 * 2. PENGGANTIAN BERKAS BISA KETAHUAN lewat sidiknya.
 * 3. RALAT TIDAK MENIMPA: yang lama tetap ada dan menunjuk penggantinya.
 * 4. TANGGAL RETENSI DIHITUNG, dan pemusnahan menuntut berita acara
 *    serta tidak boleh mendahului masa simpannya.
 */
class MedicalRecordFileTest extends TestCase
{
    use RefreshDatabase;

    private MedicalRecordFileService $berkas;

    private User $petugasRm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->berkas = app(MedicalRecordFileService::class);

        $this->petugasRm = User::query()->create([
            'username' => 'uji-berkas-rm', 'name' => 'Petugas Rekam Medis',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    // ============================================== jejak unggahan

    #[Test]
    public function berkas_tercatat_lengkap_dengan_pengunggah_waktu_dan_sidiknya(): void
    {
        $kunjungan = $this->daftarkan();

        $dokumen = $this->berkas->attach($kunjungan->id, 'RUJUKAN-MASUK', $this->berkasContoh(), $this->petugasRm);

        // Khanza hanya menyimpan tiga kolom: no_rawat, kode, lokasi_file.
        $this->assertSame('Petugas Rekam Medis', $dokumen->uploaded_by_name);
        $this->assertNotNull($dokumen->uploaded_at);
        $this->assertSame('application/pdf', $dokumen->mime_type);
        $this->assertSame(204800, $dokumen->size_bytes);
        $this->assertSame(64, strlen($dokumen->checksum_sha256));
        $this->assertSame('Surat rujukan dari fasilitas lain', $dokumen->document_type_name);
    }

    #[Test]
    public function berkas_tanpa_sidik_ditolak(): void
    {
        $kunjungan = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/tidak akan pernah ketahuan/');

        $this->berkas->attach($kunjungan->id, 'RUJUKAN-MASUK', [
            'original_filename' => 'rujukan.pdf', 'stored_path' => '/x/rujukan.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 100, 'checksum_sha256' => 'pendek',
        ], $this->petugasRm);
    }

    #[Test]
    public function berkas_tanpa_pengunggah_ditolak(): void
    {
        $kunjungan = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/Permenkes 24\/2022 menuntut jejaknya/');

        $this->berkas->attach($kunjungan->id, 'RUJUKAN-MASUK', $this->berkasContoh(), null);
    }

    #[Test]
    public function berkas_berukuran_nol_ditolak(): void
    {
        $kunjungan = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/harus lebih dari nol/');

        $this->berkas->attach(
            $kunjungan->id, 'RUJUKAN-MASUK',
            ['size_bytes' => 0] + $this->berkasContoh(),
            $this->petugasRm
        );
    }

    #[Test]
    public function jenis_berkas_di_luar_master_ditolak(): void
    {
        $kunjungan = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches("/'ENTAH-APA' tidak ada/");

        $this->berkas->attach($kunjungan->id, 'ENTAH-APA', $this->berkasContoh(), $this->petugasRm);
    }

    #[Test]
    public function jenis_berkas_yang_dinonaktifkan_tidak_bisa_dipakai_lagi(): void
    {
        $kunjungan = $this->daftarkan();
        DocumentType::query()->where('code', 'FOTO-KLINIS')->update(['is_active' => false]);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/sudah tidak aktif/');

        $this->berkas->attach($kunjungan->id, 'FOTO-KLINIS', $this->berkasContoh(), $this->petugasRm);
    }

    #[Test]
    public function unggahan_ganda_yang_persis_sama_ditolak(): void
    {
        $kunjungan = $this->daftarkan();
        $this->berkas->attach($kunjungan->id, 'RUJUKAN-MASUK', $this->berkasContoh(), $this->petugasRm);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/tombol unggah tertekan dua kali/');

        $this->berkas->attach($kunjungan->id, 'RUJUKAN-MASUK', $this->berkasContoh(), $this->petugasRm);
    }

    #[Test]
    public function berkas_berbeda_pada_jenis_yang_sama_tetap_boleh(): void
    {
        $kunjungan = $this->daftarkan();

        $this->berkas->attach($kunjungan->id, 'HASIL-LUAR', $this->berkasContoh('a'), $this->petugasRm);
        $this->berkas->attach($kunjungan->id, 'HASIL-LUAR', $this->berkasContoh('b'), $this->petugasRm);

        // Pasien memang bisa membawa dua hasil penunjang dari luar.
        $this->assertCount(2, $this->berkas->forRegistration($kunjungan->id, 'HASIL-LUAR'));
    }

    // ============================================== sidik & penggantian

    #[Test]
    public function penggantian_isi_berkas_bisa_ketahuan_dari_sidiknya(): void
    {
        $kunjungan = $this->daftarkan();
        $dokumen = $this->berkas->attach($kunjungan->id, 'RUJUKAN-MASUK', $this->berkasContoh(), $this->petugasRm);

        $this->assertTrue($this->berkas->verify($dokumen, hash('sha256', 'isi-berkas-')));

        // Berkas di jalur yang sama ditimpa isi lain: pada Khanza ini tidak
        // meninggalkan bekas apa pun.
        $this->assertFalse($this->berkas->verify($dokumen, hash('sha256', 'isi-yang-diganti')));
    }

    #[Test]
    public function sidik_pembanding_yang_bukan_sha256_ditolak(): void
    {
        $kunjungan = $this->daftarkan();
        $dokumen = $this->berkas->attach($kunjungan->id, 'RUJUKAN-MASUK', $this->berkasContoh(), $this->petugasRm);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/64 karakter/');

        $this->berkas->verify($dokumen, 'abc');
    }

    #[Test]
    public function basis_data_menolak_sidik_yang_bukan_sha256(): void
    {
        $kunjungan = $this->daftarkan();
        $dokumen = $this->berkas->attach($kunjungan->id, 'RUJUKAN-MASUK', $this->berkasContoh(), $this->petugasRm);

        $this->expectException(QueryException::class);

        MedicalRecordDocument::query()->whereKey($dokumen->id)
            ->update(['checksum_sha256' => 'bukan sidik']);
    }

    #[Test]
    public function ralat_berkas_tidak_menimpa_yang_lama(): void
    {
        $kunjungan = $this->daftarkan();
        $lama = $this->berkas->attach($kunjungan->id, 'RUJUKAN-MASUK', $this->berkasContoh('salah'), $this->petugasRm);

        $baru = $this->berkas->replace($lama, $this->berkasContoh('benar'), $this->petugasRm);

        // Rekam medis yang bisa dihapus tanpa jejak bukan rekam medis.
        $this->assertSame(MedicalRecordDocument::DIGANTI, $lama->fresh()->status);
        $this->assertSame($baru->id, $lama->fresh()->superseded_by_id);
        $this->assertSame(MedicalRecordDocument::AKTIF, $baru->status);

        // Yang berlaku tinggal satu, tapi yang lama masih bisa ditelusuri.
        $this->assertCount(1, $this->berkas->forRegistration($kunjungan->id, 'RUJUKAN-MASUK'));
        $this->assertNotNull($lama->fresh()->supersededBy);
    }

    #[Test]
    public function berkas_yang_sudah_diganti_tidak_bisa_diganti_lagi(): void
    {
        $kunjungan = $this->daftarkan();
        $lama = $this->berkas->attach($kunjungan->id, 'RUJUKAN-MASUK', $this->berkasContoh('1'), $this->petugasRm);
        $this->berkas->replace($lama, $this->berkasContoh('2'), $this->petugasRm);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/Ganti berkas penggantinya/');

        $this->berkas->replace($lama->refresh(), $this->berkasContoh('3'), $this->petugasRm);
    }

    #[Test]
    public function pembatalan_berkas_wajib_menyebut_alasan(): void
    {
        $kunjungan = $this->daftarkan();
        $dokumen = $this->berkas->attach($kunjungan->id, 'RUJUKAN-MASUK', $this->berkasContoh(), $this->petugasRm);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/Alasan pembatalan wajib diisi/');

        $this->berkas->cancel($dokumen, '  ');
    }

    #[Test]
    public function basis_data_menolak_status_diganti_tanpa_penggantinya(): void
    {
        $kunjungan = $this->daftarkan();
        $dokumen = $this->berkas->attach($kunjungan->id, 'RUJUKAN-MASUK', $this->berkasContoh(), $this->petugasRm);

        $this->expectException(QueryException::class);

        MedicalRecordDocument::query()->whereKey($dokumen->id)
            ->update(['status' => MedicalRecordDocument::DIGANTI]);
    }

    // ============================================== retensi

    #[Test]
    public function tanggal_retensi_dihitung_dari_kunjungan_terakhir(): void
    {
        $kunjungan = $this->daftarkan();
        $catatan = $this->berkas->refreshRetention($kunjungan->patient_id);

        // Permenkes 24/2022 Pasal 39: 25 tahun sejak kunjungan terakhir.
        $this->assertSame(
            $catatan->last_visit_on->copy()->addYears(25)->toDateString(),
            $catatan->dueOn()->toDateString()
        );

        // Khanza menyimpan tgl_retensi sebagai kolom yang bisa berbeda dari
        // terakhir_daftar yang melahirkannya.
        $this->assertArrayNotHasKey('tgl_retensi', $catatan->getAttributes());
        $this->assertArrayNotHasKey('due_on', $catatan->getAttributes());
    }

    #[Test]
    public function pasien_yang_berobat_lagi_memundurkan_masa_simpannya(): void
    {
        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Retensi', 'sex' => 'L', 'birth_date' => '1960-01-01',
        ]);

        // 200 hari, bukan lebih: praktisi contoh baru aktif sejak awal tahun
        // ini, dan kunjungan sebelum masa aktifnya memang ditolak registrasi.
        $this->daftarkan($pasien, 200);
        $lama = $this->berkas->refreshRetention($pasien->id);
        $tanggalLama = $lama->dueOn()->toDateString();

        $this->daftarkan($pasien, 0);
        $baru = $this->berkas->refreshRetention($pasien->id);

        // Pada Khanza, terakhir_daftar yang tidak diperbarui akan membuat
        // rekam medis pasien aktif diusulkan musnah.
        $this->assertGreaterThan($tanggalLama, $baru->dueOn()->toDateString());
        $this->assertSame($lama->id, $baru->id);
    }

    #[Test]
    public function rekam_medis_yang_belum_lewat_masa_simpan_tidak_bisa_diusulkan_musnah(): void
    {
        $kunjungan = $this->daftarkan();
        $catatan = $this->berkas->refreshRetention($kunjungan->patient_id);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/Belum boleh dimusnahkan/');

        $this->berkas->propose($catatan, 'USUL-001');
    }

    #[Test]
    public function rekam_medis_lewat_masa_simpan_bisa_diusulkan_lalu_dimusnahkan(): void
    {
        $catatan = $this->retensiLewatTenggat();

        $diusulkan = $this->berkas->propose($catatan, 'USUL-2026-001');
        $this->assertSame(RecordRetention::DIUSULKAN_MUSNAH, $diusulkan->status);

        $dimusnahkan = $this->berkas->destroy(
            $diusulkan, 'BA-2026-014', '/arsip/ringkasan.pdf', $this->petugasRm
        );

        $this->assertSame(RecordRetention::DIMUSNAHKAN, $dimusnahkan->status);
        $this->assertNotNull($dimusnahkan->destroyed_at);
        $this->assertSame('BA-2026-014', $dimusnahkan->destruction_decision_number);
        $this->assertSame('/arsip/ringkasan.pdf', $dimusnahkan->summary_path);
    }

    #[Test]
    public function pemusnahan_tanpa_berita_acara_ditolak(): void
    {
        $catatan = $this->berkas->propose($this->retensiLewatTenggat(), 'USUL-002');

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/bukan kehendak seorang petugas/');

        $this->berkas->destroy($catatan, '   ', null, $this->petugasRm);
    }

    #[Test]
    public function pemusnahan_tanpa_usulan_lebih_dulu_ditolak(): void
    {
        $catatan = $this->retensiLewatTenggat();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/Usulkan lebih dulu/');

        $this->berkas->destroy($catatan, 'BA-2026-015', null, $this->petugasRm);
    }

    #[Test]
    public function rekam_medis_yang_diabadikan_tidak_ikut_dimusnahkan(): void
    {
        $catatan = $this->berkas->markPermanent(
            $this->retensiLewatTenggat(),
            'Kasus hukum yang masih berjalan'
        );

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/diabadikan dan tidak ikut dimusnahkan/');

        $this->berkas->propose($catatan, 'USUL-003');
    }

    #[Test]
    public function daftar_lewat_tenggat_hanya_memuat_yang_masih_aktif(): void
    {
        $lewat = $this->retensiLewatTenggat();
        $kunjungan = $this->daftarkan();
        $baru = $this->berkas->refreshRetention($kunjungan->patient_id);

        $daftar = $this->berkas->due()->pluck('id')->all();

        $this->assertContains($lewat->id, $daftar);
        $this->assertNotContains($baru->id, $daftar);

        $this->berkas->markPermanent($lewat, 'Tokoh sejarah rumah sakit');

        $this->assertNotContains($lewat->id, $this->berkas->due()->pluck('id')->all());
    }

    #[Test]
    public function basis_data_menolak_pemusnahan_tanpa_berita_acara(): void
    {
        $catatan = $this->retensiLewatTenggat();

        $this->expectException(QueryException::class);

        RecordRetention::query()->whereKey($catatan->id)->update([
            'status' => RecordRetention::DIMUSNAHKAN, 'destroyed_at' => now(),
        ]);
    }

    #[Test]
    public function pasien_tanpa_kunjungan_belum_punya_masa_simpan(): void
    {
        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Belum Berobat', 'sex' => 'P', 'birth_date' => '1990-01-01',
        ]);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/belum mulai berjalan/');

        $this->berkas->refreshRetention($pasien->id);
    }

    // ============================================== master jenis berkas

    #[Test]
    public function jenis_berkas_bertanda_tangan_basah_ditandai(): void
    {
        $persetujuan = DocumentType::query()->where('code', 'PERSETUJUAN-TINDAKAN')->firstOrFail();
        $rujukan = DocumentType::query()->where('code', 'RUJUKAN-MASUK')->firstOrFail();

        // Inilah alasan berkas pindaian tetap ada meski rekam medisnya
        // elektronik.
        $this->assertTrue($persetujuan->needs_wet_signature);
        $this->assertTrue($persetujuan->is_permanent);
        $this->assertFalse($rujukan->needs_wet_signature);
    }

    // ---------------------------------------------------------------- fixture

    private function retensiLewatTenggat(): RecordRetention
    {
        $kunjungan = $this->daftarkan();
        $catatan = $this->berkas->refreshRetention($kunjungan->patient_id);

        // Digeser langsung: menciptakan kunjungan 26 tahun lalu menuntut
        // data registrasi yang tidak relevan bagi uji ini.
        $catatan->update(['last_visit_on' => now()->subYears(26)->toDateString()]);

        return $catatan->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function berkasContoh(string $varian = ''): array
    {
        return [
            'original_filename' => "rujukan{$varian}.pdf",
            'stored_path' => "/berkas/rujukan{$varian}.pdf",
            'mime_type' => 'application/pdf',
            'size_bytes' => 204800,
            'checksum_sha256' => hash('sha256', 'isi-berkas-'.$varian),
        ];
    }

    private function daftarkan(?object $pasien = null, int $mundurHari = 0): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien ??= app(PatientRegistry::class)->register([
            'name' => 'Pasien Berkas '.$urut, 'sex' => 'L', 'birth_date' => '1975-09-09',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
            practitionerId: Practitioner::query()->where('is_active', true)->value('id'),
            serviceDate: now()->subDays($mundurHari),
        );
    }
}
