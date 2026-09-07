<?php

namespace Tests\Feature\Clinical;

use App\Modules\Catalog\Models\DisabilityType;
use App\Modules\Catalog\Models\FormTemplate;
use App\Modules\Catalog\Models\ImmunisationType;
use App\Modules\Catalog\Models\Payer;
use App\Modules\Clinical\Models\Immunisation;
use App\Modules\Clinical\Models\PatientDisability;
use App\Modules\Clinical\Services\ClinicalException;
use App\Modules\Clinical\Services\ImmunisationService;
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
 * Riwayat imunisasi & cacat fisik (domain M item S).
 *
 * Yang paling perlu dikunci:
 *
 * 1. TANGGAL PEMBERIAN WAJIB — riwayat_imunisasi Khanza tidak punya
 *    kolomnya sama sekali, jadi jadwal dosis berikutnya tidak bisa
 *    dihitung.
 * 2. NOMOR BATCH MEMBUAT PENARIKAN VAKSIN BISA DITINDAKLANJUTI.
 * 3. DOSIS DARI LUAR DIBEDAKAN dari yang kita suntikkan sendiri.
 * 4. CACAT FISIK PUNYA TABEL PEMAKAIANNYA — di Khanza cuma masternya.
 */
class ImmunisationTest extends TestCase
{
    use RefreshDatabase;

    private ImmunisationService $imunisasi;

    private User $perawat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->imunisasi = app(ImmunisationService::class);

        $this->perawat = User::query()->create([
            'username' => 'uji-imunisasi', 'name' => 'Ns. Imunisasi',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    // ============================================== tanggal & jadwal

    #[Test]
    public function imunisasi_tercatat_lengkap_dengan_tanggal_batch_dan_penyuntiknya(): void
    {
        $kunjungan = $this->daftarkan();

        $catatan = $this->suntik($kunjungan, 'DPT-HB-HIB', 1);

        // riwayat_imunisasi Khanza hanya punya tiga kolom: pasien, kode
        // vaksin, dan nomor dosis.
        $this->assertNotNull($catatan->given_on);
        $this->assertNotNull($catatan->batch_number);
        $this->assertSame('Ns. Imunisasi', $catatan->given_by_name);
        $this->assertSame('DPT-HB-Hib', $catatan->immunisation_name);
    }

    #[Test]
    public function imunisasi_tanpa_tanggal_ditolak(): void
    {
        $kunjungan = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/jadwal dosis berikutnya tidak bisa dihitung/');

        $this->imunisasi->record($kunjungan->patient_id, 'BCG', [
            'dose_number' => 1, 'batch_number' => 'B-001',
        ], $this->perawat);
    }

    #[Test]
    public function jadwal_dosis_berikutnya_dihitung_dari_tanggal_pemberian(): void
    {
        $kunjungan = $this->daftarkan();

        $dosis1 = $this->suntik($kunjungan, 'DPT-HB-HIB', 1, ['given_on' => now()->subDays(10)->toDateString()]);

        $jadwal = $this->imunisasi->nextDoseFor($kunjungan->patient_id, 'DPT-HB-HIB');

        // Jarak DPT-HB-Hib 28 hari; inilah yang mustahil dihitung di
        // Khanza karena tanggalnya tidak ada.
        $this->assertSame('terjadwal', $jadwal['status']);
        $this->assertSame(2, $jadwal['next_dose']);
        $this->assertSame(
            $dosis1->given_on->copy()->addDays(28)->toDateString(),
            $jadwal['due_on']
        );
    }

    #[Test]
    public function pasien_yang_belum_pernah_diimunisasi_dinyatakan_belum_mulai(): void
    {
        $kunjungan = $this->daftarkan();

        $jadwal = $this->imunisasi->nextDoseFor($kunjungan->patient_id, 'BCG');

        $this->assertSame('belum-mulai', $jadwal['status']);
        $this->assertSame(1, $jadwal['next_dose']);
    }

    #[Test]
    public function seri_yang_sudah_lengkap_dibedakan_dari_belum_terjadwal(): void
    {
        $kunjungan = $this->daftarkan();
        $this->suntik($kunjungan, 'BCG', 1);

        // BCG hanya satu dosis, jadi serinya lengkap — berbeda dari
        // "jaraknya belum diatur" pada vaksin yang jadwalnya menurut umur.
        $lengkap = $this->imunisasi->nextDoseFor($kunjungan->patient_id, 'BCG');
        $this->assertSame('lengkap', $lengkap['status']);
        $this->assertNull($lengkap['next_dose']);

        $this->suntik($kunjungan, 'CAMPAK-RUBELA', 1);
        $menurutUmur = $this->imunisasi->nextDoseFor($kunjungan->patient_id, 'CAMPAK-RUBELA');
        $this->assertSame('jarak-belum-diatur', $menurutUmur['status']);
        $this->assertSame(2, $menurutUmur['next_dose']);
    }

    #[Test]
    public function dosis_melebihi_seri_lengkap_ditolak(): void
    {
        $kunjungan = $this->daftarkan();
        $this->suntik($kunjungan, 'BCG', 1);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/melebihi jumlah dosis lengkap/');

        $this->suntik($kunjungan, 'BCG', 2);
    }

    #[Test]
    public function vaksin_tanpa_batas_dosis_boleh_diulang(): void
    {
        $kunjungan = $this->daftarkan();

        // Td diulang sesuai status imunisasi tetanus, tidak berbatas.
        $this->suntik($kunjungan, 'TD', 1);
        $keempat = $this->suntik($kunjungan, 'TD', 4);

        $this->assertSame(4, $keempat->dose_number);
        $this->assertTrue(ImmunisationType::query()->where('code', 'TD')->first()->isOpenEnded());
    }

    #[Test]
    public function dosis_yang_sama_tidak_bisa_tercatat_dua_kali(): void
    {
        $kunjungan = $this->daftarkan();
        $this->suntik($kunjungan, 'PCV', 1);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/sudah tercatat untuk pasien ini/');

        $this->suntik($kunjungan, 'PCV', 1);
    }

    #[Test]
    public function dosis_yang_dibatalkan_boleh_dicatat_ulang(): void
    {
        $kunjungan = $this->daftarkan();
        $keliru = $this->suntik($kunjungan, 'PCV', 1);

        $this->imunisasi->cancel($keliru, 'Salah pilih jenis vaksin');
        $benar = $this->suntik($kunjungan, 'PCV', 1);

        $this->assertNotSame($keliru->id, $benar->id);
        $this->assertCount(1, $this->imunisasi->historyFor($kunjungan->patient_id));
    }

    #[Test]
    public function tanggal_pemberian_di_masa_depan_ditolak(): void
    {
        $kunjungan = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/tidak boleh di masa depan/');

        $this->suntik($kunjungan, 'BCG', 1, ['given_on' => now()->addWeek()->toDateString()]);
    }

    // ============================================== batch & penarikan

    #[Test]
    public function penarikan_batch_bisa_menemukan_pasien_yang_menerimanya(): void
    {
        $satu = $this->daftarkan();
        $dua = $this->daftarkan();
        $lain = $this->daftarkan();

        $this->suntik($satu, 'PCV', 1, ['batch_number' => 'BATCH-TARIK']);
        $this->suntik($dua, 'PCV', 1, ['batch_number' => 'BATCH-TARIK']);
        $this->suntik($lain, 'PCV', 1, ['batch_number' => 'BATCH-AMAN']);

        // Pada riwayat_imunisasi Khanza pertanyaan ini tidak bisa diajukan
        // sama sekali: nomor batchnya tidak ada.
        $penerima = $this->imunisasi->recipientsOfBatch('BATCH-TARIK')->pluck('patient_id')->all();

        $this->assertContains($satu->patient_id, $penerima);
        $this->assertContains($dua->patient_id, $penerima);
        $this->assertNotContains($lain->patient_id, $penerima);
    }

    #[Test]
    public function vaksin_yang_disuntikkan_di_sini_wajib_menyebut_batchnya(): void
    {
        $kunjungan = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/menemukan pasien yang menerimanya/');

        $this->imunisasi->record($kunjungan->patient_id, 'BCG', [
            'dose_number' => 1, 'given_on' => now()->toDateString(),
        ], $this->perawat);
    }

    #[Test]
    public function dosis_dari_fasilitas_lain_tidak_menuntut_batch(): void
    {
        $kunjungan = $this->daftarkan();

        // Kita memang tidak memilikinya, dan mewajibkannya hanya akan
        // melahirkan nomor karangan.
        $catatan = $this->imunisasi->record($kunjungan->patient_id, 'BCG', [
            'dose_number' => 1,
            'given_on' => now()->subYears(2)->toDateString(),
            'source' => 'dilaporkan-keluarga',
        ], $this->perawat);

        $this->assertNull($catatan->batch_number);
        $this->assertTrue($catatan->isSelfReported());
    }

    #[Test]
    public function vaksin_yang_sudah_kedaluwarsa_saat_disuntikkan_ditolak(): void
    {
        $kunjungan = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/harus ditindaklanjuti, bukan dicatat begitu saja/');

        $this->suntik($kunjungan, 'PCV', 1, [
            'given_on' => now()->toDateString(),
            'expires_on' => now()->subMonth()->toDateString(),
        ]);
    }

    #[Test]
    public function basis_data_menolak_kedaluwarsa_sebelum_pemberian(): void
    {
        $kunjungan = $this->daftarkan();
        $catatan = $this->suntik($kunjungan, 'PCV', 1);

        $this->expectException(QueryException::class);

        Immunisation::query()->whereKey($catatan->id)
            ->update(['expires_on' => now()->subYear()->toDateString()]);
    }

    // ============================================== cacat fisik

    #[Test]
    public function cacat_fisik_pasien_punya_tempat_pencatatannya(): void
    {
        $kunjungan = $this->daftarkan();

        // Inilah separuh yang hilang di Khanza: cacat_fisik di sana hanya
        // master, tanpa tabel yang mencatat pasien mana punya yang mana.
        $catatan = $this->imunisasi->recordDisability($kunjungan->patient_id, 'SENSORIK-DENGAR', [
            'onset' => PatientDisability::DIDAPAT,
            'onset_on' => now()->subYears(5)->toDateString(),
            'severity' => 'sedang',
            'assistive_device' => 'Alat bantu dengar telinga kanan',
            'service_adjustment' => 'Bicara menghadap pasien, pastikan alat bantunya terpasang.',
        ], $this->perawat);

        $this->assertSame('Gangguan pendengaran', $catatan->disability_name);
        $this->assertTrue($catatan->needsServiceAdjustment());
    }

    #[Test]
    public function penyesuaian_pelayanan_bisa_dibaca_sekaligus(): void
    {
        $kunjungan = $this->daftarkan();

        $this->imunisasi->recordDisability($kunjungan->patient_id, 'FISIK-LUMPUH', [
            'assistive_device' => 'Kursi roda',
        ], $this->perawat);
        $this->imunisasi->recordDisability($kunjungan->patient_id, 'SENSORIK-LIHAT', [
            'service_adjustment' => 'Dampingi saat berpindah ruang.',
        ], $this->perawat);

        $penyesuaian = $this->imunisasi->serviceAdjustmentsFor($kunjungan->patient_id);

        $this->assertCount(2, $penyesuaian);
        $this->assertStringContainsString('Kursi roda', implode(' ', $penyesuaian));
    }

    #[Test]
    public function cacat_bawaan_tidak_menyebut_tanggal_mulai(): void
    {
        $kunjungan = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/ia ada sejak lahir/');

        $this->imunisasi->recordDisability($kunjungan->patient_id, 'INTELEKTUAL', [
            'onset' => PatientDisability::BAWAAN,
            'onset_on' => now()->subYears(3)->toDateString(),
        ], $this->perawat);
    }

    #[Test]
    public function basis_data_menolak_cacat_bawaan_bertanggal_mulai(): void
    {
        $kunjungan = $this->daftarkan();
        $catatan = $this->imunisasi->recordDisability($kunjungan->patient_id, 'INTELEKTUAL', [
            'onset' => PatientDisability::BAWAAN,
        ], $this->perawat);

        $this->expectException(QueryException::class);

        PatientDisability::query()->whereKey($catatan->id)
            ->update(['onset_on' => now()->subYear()->toDateString()]);
    }

    #[Test]
    public function cacat_yang_sama_tidak_tercatat_aktif_dua_kali(): void
    {
        $kunjungan = $this->daftarkan();
        $this->imunisasi->recordDisability($kunjungan->patient_id, 'FISIK-GERAK', [], $this->perawat);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/sudah tercatat aktif/');

        $this->imunisasi->recordDisability($kunjungan->patient_id, 'FISIK-GERAK', [], $this->perawat);
    }

    #[Test]
    public function menutup_catatan_cacat_wajib_beralasan(): void
    {
        $kunjungan = $this->daftarkan();
        $catatan = $this->imunisasi->recordDisability($kunjungan->patient_id, 'FISIK-GERAK', [], $this->perawat);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/adalah pernyataan besar/');

        $this->imunisasi->closeDisability($catatan, PatientDisability::PULIH, '  ');
    }

    #[Test]
    public function catatan_yang_ditutup_membuka_jalan_pencatatan_baru(): void
    {
        $kunjungan = $this->daftarkan();
        $keliru = $this->imunisasi->recordDisability($kunjungan->patient_id, 'FISIK-GERAK', [], $this->perawat);

        $this->imunisasi->closeDisability(
            $keliru, PatientDisability::DIKOREKSI, 'Tercatat pada pasien yang salah'
        );

        $benar = $this->imunisasi->recordDisability($kunjungan->patient_id, 'FISIK-GERAK', [
            'severity' => 'ringan',
        ], $this->perawat);

        $this->assertNotSame($keliru->id, $benar->id);
        $this->assertCount(1, $this->imunisasi->disabilitiesFor($kunjungan->patient_id));
    }

    // ============================================== master & template

    #[Test]
    public function master_imunisasi_menyimpan_jumlah_dosis_dan_jaraknya(): void
    {
        $dpt = ImmunisationType::query()->where('code', 'DPT-HB-HIB')->firstOrFail();

        // Keduanya tidak ada di master_imunisasi Khanza yang cuma punya
        // kode dan nama.
        $this->assertSame(4, $dpt->total_doses);
        $this->assertSame(28, $dpt->interval_days);
        $this->assertTrue($dpt->is_national_programme);
    }

    #[Test]
    public function ragam_disabilitas_berkategori_menurut_undang_undang(): void
    {
        $kategori = DisabilityType::query()->pluck('category')->unique()->all();

        // UU 8/2016: fisik, intelektual, mental, sensorik, dan ganda.
        foreach (['fisik', 'sensorik', 'intelektual', 'mental', 'ganda'] as $ragam) {
            $this->assertContains($ragam, $kategori);
        }
    }

    #[Test]
    public function kategori_template_triase_tersedia_tapi_isinya_belum_dikarang(): void
    {
        // master_triase_* Khanza sebenarnya instrumen berskala, dan
        // mekanismenya sudah ada sejak item A. Yang TIDAK dilakukan:
        // mengarang kriteria triasenya — itu keputusan komite medik.
        $template = FormTemplate::query()->create([
            'code' => 'triase-uji', 'version' => 1, 'name' => 'Triase Uji',
            'category' => 'triase', 'sections' => [], 'is_active' => true,
        ]);

        $this->assertSame('triase', $template->category);
        $this->assertSame(0, FormTemplate::query()->where('category', 'triase')
            ->where('code', '<>', 'triase-uji')->count());
    }

    #[Test]
    public function basis_data_menolak_kategori_template_yang_tidak_dikenal(): void
    {
        $this->expectException(QueryException::class);

        FormTemplate::query()->create([
            'code' => 'kategori-ngawur', 'version' => 1, 'name' => 'Ngawur',
            'category' => 'entah-apa', 'sections' => [], 'is_active' => true,
        ]);
    }

    // ---------------------------------------------------------------- fixture

    private function suntik(Registration $kunjungan, string $kode, int $dosis, array $data = []): Immunisation
    {
        return $this->imunisasi->record($kunjungan->patient_id, $kode, $data + [
            'dose_number' => $dosis,
            'given_on' => now()->toDateString(),
            'batch_number' => 'BATCH-'.$kode.'-'.$dosis,
            'registration_id' => $kunjungan->id,
        ], $this->perawat);
    }

    private function daftarkan(): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Imunisasi '.$urut, 'sex' => 'L', 'birth_date' => '2024-01-01',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-ANAK')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
            practitionerId: Practitioner::query()->where('is_active', true)->value('id'),
        );
    }
}
