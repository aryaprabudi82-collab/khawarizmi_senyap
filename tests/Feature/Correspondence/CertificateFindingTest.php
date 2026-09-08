<?php

namespace Tests\Feature\Correspondence;

use App\Modules\Correspondence\Models\MedicalCertificate;
use App\Modules\Correspondence\Services\CertificateService;
use App\Modules\Correspondence\Services\ControlLetterService;
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
 * Surat keterangan yang menyatakan temuan (domain P item C).
 *
 * Yang dikunci:
 *
 * 1. SURAT "BEBAS X" HARUS BISA MENYATAKAN "X DITEMUKAN" — dan judul
 *    cetaknya berubah mengikuti temuan, bukan mengikuti jenis surat.
 * 2. DIAGNOSIS PASIEN TIDAK BOLEH ADA DI SURAT SAKIT PIHAK KEDUA.
 * 3. PERIODE RAWAT INAP DISALIN DARI ADMISI, bukan diketik.
 * 4. SURAT KONTROL TIDAK BOLEH BERTANGGAL MUNDUR, dan yang terlewat
 *    DIHITUNG, bukan disimpan sebagai status keempat.
 */
class CertificateFindingTest extends TestCase
{
    use RefreshDatabase;

    private CertificateService $surat;

    private ControlLetterService $kontrol;

    private User $dokter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->surat = app(CertificateService::class);
        $this->kontrol = app(ControlLetterService::class);

        $this->dokter = User::query()->create([
            'username' => 'uji-dokter-surat', 'name' => 'Dokter Uji Surat',
            'password' => 'password', 'is_active' => true,
        ]);
        $this->dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());
    }

    // ================================================ temuan pemeriksaan

    #[Test]
    public function surat_bebas_tato_bisa_menyatakan_bahwa_pasien_bertato(): void
    {
        $surat = $this->terbitkan([
            'certificate_type' => 'bebas_tato',
            'examination_result' => 'Ditemukan tato pada lengan kiri atas, ukuran ± 8 cm',
            'is_clear' => false,
        ]);

        $this->assertFalse($surat->is_clear);

        /*
         * Dan yang menentukan: judulnya berubah. Surat yang secara struktur
         * tidak bisa memuat temuan yang tidak diinginkan bukan surat
         * keterangan, ia formulir kelulusan.
         */
        $this->assertSame('Surat Keterangan Hasil Pemeriksaan Tato', $surat->judul());
        $this->assertStringNotContainsStringIgnoringCase('bebas', $surat->judul());
    }

    #[Test]
    public function surat_bebas_tato_yang_temuannya_bersih_tetap_berjudul_bebas(): void
    {
        $surat = $this->terbitkan([
            'certificate_type' => 'bebas_tato',
            'examination_result' => 'Tidak ditemukan tato pada seluruh permukaan tubuh yang diperiksa',
            'is_clear' => true,
        ]);

        $this->assertSame('Surat Keterangan Bebas Tato', $surat->judul());
    }

    #[Test]
    public function surat_bertemuan_tanpa_kesimpulan_ditolak(): void
    {
        $this->expectException(CorrespondenceException::class);
        $this->expectExceptionMessageMatches('/berlawanan dengan judul suratnya/');

        $this->terbitkan(['certificate_type' => 'tidak_hamil']);
    }

    #[Test]
    public function surat_bertemuan_tanpa_uraian_temuan_ditolak(): void
    {
        // Kesimpulan tanpa temuan tidak bisa diperiksa ulang siapa pun.
        $this->expectException(CorrespondenceException::class);
        $this->expectExceptionMessageMatches('/bukan hanya kesimpulannya/');

        $this->terbitkan([
            'certificate_type' => 'bebas_narkoba',
            'is_clear' => true,
            'examination_result' => '   ',
        ]);
    }

    #[Test]
    public function surat_yang_tidak_memeriksa_apa_pun_tidak_boleh_punya_kesimpulan(): void
    {
        // Kalau boleh, is_clear pada surat keterangan berobat akan terbaca
        // sebagai penilaian atas sesuatu yang tidak pernah dinilai.
        $this->expectException(CorrespondenceException::class);
        $this->expectExceptionMessageMatches('/tidak memeriksa apa pun/');

        $this->terbitkan(['certificate_type' => 'berobat', 'is_clear' => true]);
    }

    #[Test]
    public function basis_data_menolak_surat_bertemuan_tanpa_kesimpulan(): void
    {
        $this->expectException(QueryException::class);

        DB::table('correspondence.medical_certificates')->insert([
            'certificate_number' => 'SKT-UJI-LANGSUNG', 'certificate_type' => 'bebas_tbc',
            'patient_name' => 'Budi', 'purpose' => 'melamar kerja', 'content' => 'isi',
            'valid_from' => now()->toDateString(), 'issued_at' => now(), 'status' => 'diterbitkan',
            'is_clear' => null, 'examination_result' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    #[Test]
    public function kesimpulan_false_bukan_kosong(): void
    {
        /*
         * array_key_exists, bukan empty(): false adalah jawaban yang sah dan
         * justru jawaban yang paling perlu bisa dicatat. Uji ini ada karena
         * pemeriksaan dengan empty() akan menolak surat yang temuannya
         * memberatkan pemohon — dan lolos untuk yang menguntungkan.
         */
        $surat = $this->terbitkan([
            'certificate_type' => 'covid',
            'examination_result' => 'Antigen SARS-CoV-2 reaktif',
            'is_clear' => false,
        ]);

        $this->assertFalse($surat->is_clear);
        $this->assertTrue($surat->memeriksaSesuatu());
    }

    // ================================================ surat pihak kedua

    #[Test]
    public function diagnosis_pasien_tidak_boleh_ada_di_surat_sakit_pihak_kedua(): void
    {
        /*
         * Suratnya diserahkan kepada atasan ORANG LAIN. Menuliskan diagnosis
         * pasien di situ berarti menyerahkan rahasia medis seorang pasien
         * kepada perusahaan tempat kerabatnya bekerja.
         */
        $this->expectException(CorrespondenceException::class);
        $this->expectExceptionMessageMatches('/atasan orang lain/');

        $this->terbitkan([
            'certificate_type' => 'sakit_pihak_kedua',
            'third_party_name' => 'Siti Aminah', 'third_party_relationship' => 'istri',
            'diagnosis' => 'Demam berdarah dengue',
        ]);
    }

    #[Test]
    public function basis_data_juga_menolak_diagnosis_pada_surat_pihak_kedua(): void
    {
        // Aturan kerahasiaan yang cuma jadi imbauan akan dilanggar pada hari
        // yang sibuk; aturan yang jadi batasan basis data tidak.
        $this->expectException(QueryException::class);

        DB::table('correspondence.medical_certificates')->insert([
            'certificate_number' => 'SKT-UJI-PIHAK2', 'certificate_type' => 'sakit_pihak_kedua',
            'patient_name' => 'Budi', 'purpose' => 'izin kerja', 'content' => 'isi',
            'valid_from' => now()->toDateString(), 'issued_at' => now(), 'status' => 'diterbitkan',
            'third_party_name' => 'Siti', 'third_party_relationship' => 'istri',
            'diagnosis' => 'Demam berdarah dengue',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    #[Test]
    public function surat_pihak_kedua_wajib_menyebut_orang_yang_membutuhkannya(): void
    {
        $this->expectException(CorrespondenceException::class);
        $this->expectExceptionMessageMatches('/nama dan hubungan orang yang membutuhkannya/');

        $this->terbitkan(['certificate_type' => 'sakit_pihak_kedua']);
    }

    #[Test]
    public function blok_pihak_kedua_tidak_boleh_menempel_pada_jenis_lain(): void
    {
        $this->expectException(QueryException::class);

        DB::table('correspondence.medical_certificates')->insert([
            'certificate_number' => 'SKT-UJI-SALAHTEMPEL', 'certificate_type' => 'sehat',
            'patient_name' => 'Budi', 'purpose' => 'melamar kerja', 'content' => 'isi',
            'valid_from' => now()->toDateString(), 'issued_at' => now(), 'status' => 'diterbitkan',
            'third_party_name' => 'Siti', 'third_party_relationship' => 'istri',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ============================================ keterangan rawat inap

    #[Test]
    public function periode_rawat_inap_disalin_dari_admisi_bukan_diketik(): void
    {
        $admisi = $this->buatAdmisi(now()->subDays(5), now()->subDay());

        $surat = $this->terbitkan([
            'certificate_type' => 'rawat_inap',
            'registration_id' => $admisi['registration_id'],
            // Tanggal yang DIKETIK sengaja dibuat salah.
            'valid_from' => now()->subDays(30)->toDateString(),
            'valid_until' => now()->subDays(20)->toDateString(),
        ]);

        /*
         * Surat ini dipakai untuk klaim asuransi dan izin kerja. Tanggal
         * yang diketik ulang bisa berbeda dari tanggal admisi tanpa ada yang
         * tahu, dan yang harus menyangkal suratnya sendiri adalah rumah
         * sakit.
         */
        $this->assertSame(now()->subDays(5)->toDateString(), $surat->valid_from->toDateString());
        $this->assertSame(now()->subDay()->toDateString(), $surat->valid_until->toDateString());
    }

    #[Test]
    public function pasien_yang_masih_dirawat_tidak_diberi_tanggal_pulang_karangan(): void
    {
        $admisi = $this->buatAdmisi(now()->subDays(3), null);

        $surat = $this->terbitkan([
            'certificate_type' => 'rawat_inap',
            'registration_id' => $admisi['registration_id'],
            'valid_from' => now()->toDateString(),
            'valid_until' => now()->toDateString(),
        ]);

        // Suratnya berbunyi "sampai saat ini masih dalam perawatan" —
        // dikosongkan, bukan diisi tanggal hari ini.
        $this->assertNull($surat->valid_until);
    }

    #[Test]
    public function surat_rawat_inap_tanpa_kunjungan_ditolak(): void
    {
        $this->expectException(CorrespondenceException::class);
        $this->expectExceptionMessageMatches('/disalin dari admisi, tidak diketik/');

        $this->terbitkan(['certificate_type' => 'rawat_inap']);
    }

    #[Test]
    public function surat_rawat_inap_untuk_kunjungan_tanpa_admisi_ditolak(): void
    {
        $this->expectException(CorrespondenceException::class);
        $this->expectExceptionMessageMatches('/Tidak ada admisi rawat inap/');

        $this->terbitkan(['certificate_type' => 'rawat_inap', 'registration_id' => 999999]);
    }

    // ================================================== surat kontrol

    #[Test]
    public function surat_kontrol_bertanggal_mundur_ditolak(): void
    {
        /*
         * Salah ketik yang lolos akan langsung tampil di daftar "pasien
         * tidak datang kontrol" pada hari yang sama ia diterbitkan.
         */
        $this->expectException(CorrespondenceException::class);
        $this->expectExceptionMessageMatches('/tidak boleh sudah lewat/');

        $this->kontrol->issue($this->isiKontrol(now()->subDay()->toDateString()), $this->dokter->id, 'Dokter Uji');
    }

    #[Test]
    public function surat_kontrol_yang_terlewat_dihitung_bukan_disimpan(): void
    {
        $surat = $this->kontrol->issue(
            $this->isiKontrol(now()->addDays(7)->toDateString()),
            $this->dokter->id,
            'Dokter Uji'
        );

        $this->assertFalse($surat->terlewat());
        $this->assertCount(0, $this->kontrol->missed());

        /*
         * WAKTU YANG DIMAJUKAN, bukan tanggal surat yang dimundurkan.
         * Memundurkan control_date akan melanggar control_letters_date_check
         * — dan itu benar: surat kontrol bertanggal mundur memang tidak boleh
         * ada. Yang hendak diuji di sini adalah keadaan yang muncul karena
         * HARI BERGANTI tanpa ada yang menyentuh barisnya sama sekali.
         */
        $this->travel(9)->days();

        /*
         * Status "terlewat" yang disimpan menuntut ada yang menjalankannya
         * tiap hari, dan surat yang terlewat pada hari sistem itu mati akan
         * selamanya berstatus menunggu.
         */
        $this->assertTrue($surat->refresh()->terlewat());
        $this->assertCount(1, $this->kontrol->missed());

        $this->travelBack();
    }

    #[Test]
    public function surat_kontrol_yang_sudah_diperiksa_tidak_ikut_terlewat(): void
    {
        $surat = $this->kontrol->issue(
            $this->isiKontrol(now()->addDay()->toDateString()),
            $this->dokter->id,
            'Dokter Uji'
        );

        $this->kontrol->markSeen($surat);

        $this->travel(3)->days();

        // Pasien yang sudah datang tidak pernah jadi "tidak datang", berapa
        // pun hari berlalu sesudahnya.
        $this->assertFalse($surat->refresh()->terlewat());
        $this->assertCount(0, $this->kontrol->missed());

        $this->travelBack();
    }

    #[Test]
    public function pembatalan_surat_kontrol_wajib_beralasan(): void
    {
        $surat = $this->kontrol->issue(
            $this->isiKontrol(now()->addDay()->toDateString()),
            $this->dokter->id,
            'Dokter Uji'
        );

        $this->expectException(CorrespondenceException::class);
        $this->expectExceptionMessageMatches('/menyebutkan alasannya/');

        $this->kontrol->cancel($surat, '  ');
    }

    #[Test]
    public function surat_kontrol_tidak_bisa_diubah_dua_kali(): void
    {
        $surat = $this->kontrol->issue(
            $this->isiKontrol(now()->addDay()->toDateString()),
            $this->dokter->id,
            'Dokter Uji'
        );

        $this->kontrol->markSeen($surat);

        $this->expectException(CorrespondenceException::class);
        $this->expectExceptionMessageMatches('/sudah berstatus/');

        $this->kontrol->cancel($surat->refresh(), 'Pasien membatalkan');
    }

    #[Test]
    public function surat_kontrol_tidak_membuat_booking_kunjungan(): void
    {
        $sebelum = DB::table('encounter.registrations')->count();

        $this->kontrol->issue(
            $this->isiKontrol(now()->addDays(7)->toDateString()),
            $this->dokter->id,
            'Dokter Uji'
        );

        /*
         * Booking hidup di konteks encounter dan correspondence tidak boleh
         * menulis ke sana. Dinyatakan sebagai uji supaya keterbatasan ini
         * tercatat sebagai keputusan, bukan ditemukan orang lain sebagai
         * kejutan.
         */
        $this->assertSame($sebelum, DB::table('encounter.registrations')->count());
    }

    // -------------------------------------------------------- fixture

    private function terbitkan(array $isi): MedicalCertificate
    {
        return $this->surat->issue($isi + [
            'patient_name' => 'Budi Santoso',
            'purpose' => 'untuk keperluan melamar pekerjaan',
            'content' => 'Isi keterangan',
            'valid_from' => now()->toDateString(),
        ], $this->dokter->id);
    }

    /**
     * @return array{registration_id: int, admission_id: int}
     */
    private function buatAdmisi(\DateTimeInterface $masuk, ?\DateTimeInterface $keluar): array
    {
        static $urut = 0;
        $urut++;

        $registrationId = 500000 + $urut;

        $admissionId = DB::table('inpatient.admissions')->insertGetId([
            'admission_number' => 'ADM-UJI-'.$urut,
            'registration_id' => $registrationId,
            'patient_id' => 600000 + $urut,
            'patient_mrn' => 'RM-UJI-'.$urut,
            'patient_name' => 'Budi Santoso',
            'bed_id' => $this->bedUji(),
            'admitted_at' => $masuk,
            'discharged_at' => $keluar,
            'status' => $keluar === null ? 'dirawat' : 'pulang',
            'discharge_status' => $keluar === null ? null : 'sembuh',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['registration_id' => $registrationId, 'admission_id' => $admissionId];
    }

    /**
     * Bed dibuat lewat kueri langsung, bukan lewat model Inpatient: aturan
     * batas konteks proyek ini melarang correspondence mengimpor model
     * konteks lain, dan uji ini berada di sisi correspondence.
     */
    private function bedUji(): int
    {
        // Tanpa cache statis: barisnya di-rollback antar uji, sementara
        // variabel statis bertahan sepanjang proses — id yang disimpan akan
        // menunjuk baris yang sudah tidak ada.
        $roomId = DB::table('inpatient.rooms')->insertGetId([
            'room_number' => 'UJI-SURAT', 'room_class' => 'kelas-3',
            'daily_rate' => 250000, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('inpatient.beds')->insertGetId([
            'room_id' => $roomId, 'bed_number' => 'A', 'status' => 'tersedia',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function isiKontrol(string $tanggal): array
    {
        return [
            'patient_name' => 'Budi Santoso',
            'diagnosis' => 'Diabetes melitus tipe 2',
            'therapy' => 'Metformin 500 mg 2x1',
            'control_reason' => 'Evaluasi gula darah puasa dan penyesuaian dosis',
            'follow_up_plan' => 'Pemeriksaan HbA1c pada kunjungan berikutnya',
            'control_date' => $tanggal,
            'practitioner_name' => 'dr. Andi',
        ];
    }
}
