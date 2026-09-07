<?php

namespace Tests\Feature\Clinical;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Clinical\Models\Allergy;
use App\Modules\Clinical\Models\DrugInformationRequest;
use App\Modules\Clinical\Models\MedicationReconciliation;
use App\Modules\Clinical\Models\MedicationReconciliationItem;
use App\Modules\Clinical\Models\PharmacyCounselling;
use App\Modules\Clinical\Services\ClinicalException;
use App\Modules\Clinical\Services\ClinicalRecordService;
use App\Modules\Clinical\Services\DrugInformationService;
use App\Modules\Clinical\Services\MedicationReconciliationService;
use App\Modules\Clinical\Services\PharmacyCounsellingService;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
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
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Farmasi klinis (domain M item I): rekonsiliasi obat, konseling
 * farmasi, dan pelayanan informasi obat.
 *
 * Yang paling perlu dikunci:
 *
 * 1. ALERGI DARI WAWANCARA MASUK KE DAFTAR ALERGI PASIEN, bukan ke
 *    kolom milik rekonsiliasi sendiri — supaya ikut terbaca saat resep
 *    ditelaah.
 * 2. TINDAK LANJUT PUNYA TIGA KEMUNGKINAN, dan "ubah-aturan" wajib
 *    menyebut aturan barunya.
 * 3. RANTAI KONFIRMASI BERURUTAN: diterima, dikonfirmasi, diserahkan.
 * 4. PIO BOLEH TANPA KUNJUNGAN, dan lama jawabannya DIHITUNG.
 */
class ClinicalPharmacyTest extends TestCase
{
    use RefreshDatabase;

    private MedicationReconciliationService $rekonsiliasi;

    private PharmacyCounsellingService $konseling;

    private DrugInformationService $pio;

    private User $apoteker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PermissionCatalogSeeder::class, RoleSeeder::class,
            ReferenceDataSeeder::class, PharmacySeeder::class,
        ]);

        $this->rekonsiliasi = app(MedicationReconciliationService::class);
        $this->konseling = app(PharmacyCounsellingService::class);
        $this->pio = app(DrugInformationService::class);

        $this->apoteker = User::query()->create([
            'username' => 'uji-farmasi-klinis', 'name' => 'Apt. Nuraini',
            'password' => 'password', 'is_active' => true,
        ]);
        $this->apoteker->roles()->attach(Role::query()->where('code', 'apoteker')->firstOrFail());
    }

    // ============================================== rekonsiliasi obat

    #[Test]
    public function alergi_dari_wawancara_masuk_ke_daftar_alergi_pasien(): void
    {
        $kunjungan = $this->daftarkan();
        $wawancara = $this->rekonsiliasi->open($kunjungan->id, 'admisi', [], $this->apoteker);

        $this->rekonsiliasi->recordAllergy($wawancara, 'Amoksisilin', [
            'reaction' => 'Ruam seluruh badan', 'severity' => 'berat',
        ], $this->apoteker);

        // Inti aturannya: yang membaca daftar alergi pasien — telaah resep,
        // asesmen, resume — ikut melihat temuan wawancara ini.
        $daftar = app(ClinicalRecordService::class)->allergiesFor($kunjungan->patient_id);

        $this->assertCount(1, $daftar);
        $this->assertSame('Amoksisilin', $daftar->first()->substance);
        $this->assertSame('obat', $daftar->first()->category);
        $this->assertSame(Allergy::STATUS_AKTIF, $daftar->first()->status);
    }

    #[Test]
    public function alergi_dibekukan_sebagai_salinan_saat_rekonsiliasi_difinalkan(): void
    {
        $kunjungan = $this->daftarkan();
        $wawancara = $this->rekonsiliasi->open($kunjungan->id, 'admisi', [], $this->apoteker);

        $this->rekonsiliasi->recordAllergy($wawancara, 'Amoksisilin', ['severity' => 'berat'], $this->apoteker);
        $this->rekonsiliasi->addItem($wawancara, 'Amlodipin 10 mg', [
            'dose' => '10 mg', 'frequency' => '1x sehari',
            'decision' => MedicationReconciliationItem::LANJUT,
        ]);

        $final = $this->rekonsiliasi->finalize($wawancara->refresh(), $this->apoteker);

        $this->assertCount(1, $final->allergies_at_interview);
        $this->assertSame('Amoksisilin', $final->allergies_at_interview[0]['substance']);

        // Alergi yang ditemukan SETELAH wawancara ditutup tidak mengubah
        // salinannya: dokumen menggambarkan apa yang diketahui waktu itu.
        app(ClinicalRecordService::class)->recordAllergy($kunjungan->patient_id, 'Seafood');

        $this->assertCount(1, $final->fresh()->allergies_at_interview);
    }

    #[Test]
    public function obat_dari_luar_boleh_dicatat_tanpa_ada_di_formularium(): void
    {
        $wawancara = $this->rekonsiliasi->open($this->daftarkan()->id, 'admisi', [], $this->apoteker);

        // Justru obat semacam ini yang paling perlu direkonsiliasi.
        $item = $this->rekonsiliasi->addItem($wawancara, 'Jamu pegal linu merek warung', [
            'frequency' => '2x sehari', 'source' => 'Beli sendiri di warung',
            'decision' => MedicationReconciliationItem::STOP,
            'decision_reason' => 'Kandungan tidak diketahui',
        ]);

        $this->assertNull($item->drug_id);
        $this->assertSame('Jamu pegal linu merek warung', $item->drug_name);
    }

    #[Test]
    public function ubah_aturan_wajib_menyebut_aturan_barunya(): void
    {
        $wawancara = $this->rekonsiliasi->open($this->daftarkan()->id, 'admisi', [], $this->apoteker);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/Aturan pakai yang baru wajib diisi/');

        $this->rekonsiliasi->addItem($wawancara, 'Metformin 500 mg', [
            'decision' => MedicationReconciliationItem::UBAH_ATURAN,
        ]);
    }

    #[Test]
    public function obat_yang_diteruskan_dengan_dosis_berbeda_tercatat_sebagai_ubah_aturan(): void
    {
        $wawancara = $this->rekonsiliasi->open($this->daftarkan()->id, 'admisi', [], $this->apoteker);

        // Pada Khanza kasus ini tercatat "Lanjut" begitu saja, dan
        // perubahannya hanya terbaca kalau ada yang membuka kolom teksnya.
        $item = $this->rekonsiliasi->addItem($wawancara, 'Metformin 500 mg', [
            'dose' => '500 mg', 'frequency' => '3x sehari',
            'decision' => MedicationReconciliationItem::UBAH_ATURAN,
            'new_instruction' => '500 mg 2x sehari sesudah makan',
            'decision_reason' => 'Fungsi ginjal menurun',
        ]);

        $this->assertSame(MedicationReconciliationItem::UBAH_ATURAN, $item->decision);
        $this->assertSame('500 mg 2x sehari sesudah makan', $item->new_instruction);
    }

    #[Test]
    public function basis_data_menolak_ubah_aturan_tanpa_aturan_baru(): void
    {
        $wawancara = $this->rekonsiliasi->open($this->daftarkan()->id, 'admisi', [], $this->apoteker);
        $item = $this->rekonsiliasi->addItem($wawancara, 'Metformin 500 mg');

        $this->expectException(QueryException::class);

        MedicationReconciliationItem::query()->whereKey($item->id)
            ->update(['decision' => MedicationReconciliationItem::UBAH_ATURAN]);
    }

    #[Test]
    public function rekonsiliasi_tidak_bisa_difinalkan_selama_ada_obat_tanpa_tindak_lanjut(): void
    {
        $wawancara = $this->rekonsiliasi->open($this->daftarkan()->id, 'admisi', [], $this->apoteker);

        $this->rekonsiliasi->addItem($wawancara, 'Amlodipin 10 mg', [
            'decision' => MedicationReconciliationItem::LANJUT,
        ]);
        $this->rekonsiliasi->addItem($wawancara, 'Simvastatin 20 mg');

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/Simvastatin 20 mg/');

        $this->rekonsiliasi->finalize($wawancara->refresh(), $this->apoteker);
    }

    #[Test]
    public function pasien_yang_tidak_membawa_obat_apa_pun_tetap_bisa_direkonsiliasi(): void
    {
        $wawancara = $this->rekonsiliasi->open($this->daftarkan()->id, 'admisi', [], $this->apoteker);

        // Daftar kosong adalah hasil wawancara yang sah, bukan wawancara
        // yang belum dikerjakan.
        $final = $this->rekonsiliasi->finalize($wawancara, $this->apoteker);

        $this->assertSame(MedicationReconciliation::FINAL, $final->status);
    }

    #[Test]
    public function rantai_konfirmasi_harus_berurutan(): void
    {
        $wawancara = $this->rekonsiliasi->open($this->daftarkan()->id, 'admisi', [], $this->apoteker);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/belum dikonfirmasi apoteker/');

        $this->rekonsiliasi->handToPatient($wawancara);
    }

    #[Test]
    public function konfirmasi_apoteker_menunggu_penerimaan_farmasi(): void
    {
        $wawancara = $this->rekonsiliasi->open($this->daftarkan()->id, 'admisi', [], $this->apoteker);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/belum tercatat diterima farmasi/');

        $this->rekonsiliasi->confirmByPharmacist($wawancara, $this->apoteker);
    }

    #[Test]
    public function rantai_konfirmasi_lengkap_tercatat_waktunya(): void
    {
        $wawancara = $this->rekonsiliasi->open($this->daftarkan()->id, 'admisi', [], $this->apoteker);

        $this->rekonsiliasi->receiveByPharmacy($wawancara, $this->apoteker);
        $this->rekonsiliasi->confirmByPharmacist($wawancara->refresh(), $this->apoteker);
        $selesai = $this->rekonsiliasi->handToPatient($wawancara->refresh());

        $this->assertNotNull($selesai->received_by_pharmacy_at);
        $this->assertNotNull($selesai->confirmed_by_pharmacist_at);
        $this->assertNotNull($selesai->handed_to_patient_at);
    }

    #[Test]
    public function satu_kunjungan_boleh_beberapa_kesempatan_rekonsiliasi(): void
    {
        $kunjungan = $this->daftarkan();

        $masuk = $this->rekonsiliasi->open($kunjungan->id, 'admisi', [], $this->apoteker);
        $pulang = $this->rekonsiliasi->open($kunjungan->id, 'pulang', [], $this->apoteker);

        $this->assertNotSame($masuk->id, $pulang->id);

        // Tapi kesempatan yang sama melanjutkan yang sudah ada.
        $this->assertSame($masuk->id, $this->rekonsiliasi->open($kunjungan->id, 'admisi', [], $this->apoteker)->id);
    }

    #[Test]
    public function kesempatan_di_luar_kosakata_ditolak(): void
    {
        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches("/'saat-santai' tidak dikenali/");

        $this->rekonsiliasi->open($this->daftarkan()->id, 'saat-santai', [], $this->apoteker);
    }

    #[Test]
    public function obat_yang_diteruskan_bisa_dibaca_dokter_saat_meresepkan(): void
    {
        $kunjungan = $this->daftarkan();
        $wawancara = $this->rekonsiliasi->open($kunjungan->id, 'admisi', [], $this->apoteker);

        $this->rekonsiliasi->addItem($wawancara, 'Amlodipin 10 mg', ['decision' => MedicationReconciliationItem::LANJUT]);
        $this->rekonsiliasi->addItem($wawancara, 'Metformin 500 mg', [
            'decision' => MedicationReconciliationItem::UBAH_ATURAN,
            'new_instruction' => '500 mg 2x sehari',
        ]);
        $this->rekonsiliasi->addItem($wawancara, 'Jamu pegal linu', ['decision' => MedicationReconciliationItem::STOP]);

        $this->rekonsiliasi->finalize($wawancara->refresh(), $this->apoteker);

        $diteruskan = $this->rekonsiliasi->continuedMedications($kunjungan->id)->pluck('drug_name')->all();

        $this->assertContains('Amlodipin 10 mg', $diteruskan);
        $this->assertContains('Metformin 500 mg', $diteruskan);
        $this->assertNotContains('Jamu pegal linu', $diteruskan);
    }

    #[Test]
    public function rekonsiliasi_final_tidak_bisa_ditambahi_obat(): void
    {
        $wawancara = $this->rekonsiliasi->open($this->daftarkan()->id, 'admisi', [], $this->apoteker);
        $final = $this->rekonsiliasi->finalize($wawancara, $this->apoteker);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/tidak bisa diubah/');

        $this->rekonsiliasi->addItem($final, 'Obat susulan');
    }

    // ================================================= konseling farmasi

    #[Test]
    public function konseling_membekukan_daftar_obat_dan_alergi_yang_sudah_tercatat(): void
    {
        $kunjungan = $this->daftarkan();

        app(ClinicalRecordService::class)->recordAllergy($kunjungan->patient_id, 'Penisilin', [
            'reaction' => 'Sesak', 'severity' => 'berat',
        ]);
        $this->resepDiserahkan($kunjungan);

        $catatan = $this->konseling->open($kunjungan->id, [], $this->apoteker);
        $this->konseling->save($catatan, [
            'counselling_given' => 'Dijelaskan cara minum, waktu minum, dan tanda efek samping.',
        ]);

        $final = $this->konseling->finalize($catatan->refresh(), $this->apoteker);

        // Khanza menjejalkan keduanya ke satu kolom teks 700 dan 30 karakter.
        $this->assertCount(1, $final->medications);
        $this->assertSame('2x1 tablet', $final->medications[0]['dosage_instruction']);
        $this->assertCount(1, $final->allergies);
        $this->assertSame('Penisilin', $final->allergies[0]['substance']);
    }

    #[Test]
    public function konseling_tanpa_isi_percakapan_tidak_bisa_difinalkan(): void
    {
        $catatan = $this->konseling->open($this->daftarkan()->id, [], $this->apoteker);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/tidak membuktikan percakapan itu pernah terjadi/');

        $this->konseling->finalize($catatan, $this->apoteker);
    }

    #[Test]
    public function pernah_datang_kosong_bukan_berarti_belum_pernah(): void
    {
        $catatan = $this->konseling->open($this->daftarkan()->id, [], $this->apoteker);

        $this->assertNull($catatan->is_repeat_visit);

        $this->assertFalse($this->konseling->save($catatan, ['is_repeat_visit' => false])->is_repeat_visit);
    }

    #[Test]
    public function basis_data_menolak_konseling_final_tanpa_isi(): void
    {
        $catatan = $this->konseling->open($this->daftarkan()->id, [], $this->apoteker);

        $this->expectException(QueryException::class);

        PharmacyCounselling::query()->whereKey($catatan->id)->update([
            'status' => PharmacyCounselling::FINAL,
            'finalized_at' => now(),
        ]);
    }

    #[Test]
    public function konseling_boleh_lebih_dari_satu_kali_per_kunjungan(): void
    {
        $kunjungan = $this->daftarkan();

        $pertama = $this->konseling->open($kunjungan->id, [], $this->apoteker);
        $kedua = $this->konseling->open($kunjungan->id, [], $this->apoteker);

        // Dua percakapan memang dua catatan — berbeda dari resume medis.
        $this->assertNotSame($pertama->id, $kedua->id);
        $this->assertCount(2, $this->konseling->forRegistration($kunjungan->id));
    }

    // =========================================== pelayanan informasi obat

    #[Test]
    public function pertanyaan_petugas_kesehatan_boleh_tanpa_kunjungan(): void
    {
        // Persis kasus yang tidak muat di Khanza: no_rawat NOT NULL.
        $permintaan = $this->pio->ask([
            'asker_name' => 'Ns. Dewi',
            'asker_kind' => 'petugas-kesehatan',
            'method' => 'telepon',
            'question_kind' => 'stabilitas',
            'question' => 'Berapa lama sediaan rekonstitusi seftriakson stabil pada suhu ruang?',
        ], $this->apoteker);

        $this->assertNull($permintaan->registration_id);
        $this->assertNull($permintaan->patient_id);
        $this->assertStringStartsWith('PIO', $permintaan->request_number);
        $this->assertSame(DrugInformationRequest::TERBUKA, $permintaan->status);
    }

    #[Test]
    public function pertanyaan_tentang_pasien_tetap_boleh_menunjuk_kunjungannya(): void
    {
        $kunjungan = $this->daftarkan();

        $permintaan = $this->pio->ask([
            'registration_id' => $kunjungan->id,
            'asker_name' => 'Keluarga pasien',
            'asker_kind' => 'keluarga-pasien',
            'method' => 'lisan',
            'question_kind' => 'cara-pemakaian',
            'question' => 'Bagaimana cara memakai inhaler ini?',
        ], $this->apoteker);

        $this->assertSame($kunjungan->id, $permintaan->registration_id);
        $this->assertSame($kunjungan->patient_id, $permintaan->patient_id);
    }

    #[Test]
    public function lain_lain_wajib_menyebut_keterangannya(): void
    {
        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/keranjang yang tidak bisa dibaca kembali/');

        $this->pio->ask([
            'asker_name' => 'dr. Rangga', 'asker_kind' => 'petugas-kesehatan',
            'method' => 'lisan', 'question_kind' => 'lain-lain',
            'question' => 'Pertanyaan lain.',
        ], $this->apoteker);
    }

    #[Test]
    public function jawaban_klinis_wajib_menyebut_rujukan(): void
    {
        $permintaan = $this->pio->ask([
            'asker_name' => 'dr. Rangga', 'asker_kind' => 'petugas-kesehatan',
            'method' => 'lisan', 'question_kind' => 'interaksi-obat',
            'question' => 'Apakah warfarin dan flukonazol boleh bersamaan?',
        ], $this->apoteker);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/tanpa sumber adalah pendapat/');

        $this->pio->answer($permintaan, 'Sebaiknya dihindari.', [], $this->apoteker);
    }

    #[Test]
    public function pertanyaan_administratif_tidak_menuntut_rujukan_pustaka(): void
    {
        $permintaan = $this->pio->ask([
            'asker_name' => 'Pasien Umum', 'asker_kind' => 'pasien',
            'method' => 'telepon', 'question_kind' => 'ketersediaan-obat',
            'question' => 'Apakah obat ini ada stoknya?',
        ], $this->apoteker);

        // Menuntut pustaka untuk "ada stoknya tidak" hanya melahirkan
        // rujukan yang diarang.
        $dijawab = $this->pio->answer($permintaan, 'Tersedia di depo rawat jalan.', [], $this->apoteker);

        $this->assertSame(DrugInformationRequest::DIJAWAB, $dijawab->status);
        $this->assertNull($dijawab->reference);
    }

    #[Test]
    public function lama_jawaban_dihitung_bukan_disimpan(): void
    {
        $permintaan = $this->pio->ask([
            'asker_name' => 'dr. Rangga', 'asker_kind' => 'petugas-kesehatan',
            'method' => 'tertulis', 'question_kind' => 'dosis',
            'question' => 'Dosis vankomisin pada gangguan ginjal?',
            'asked_at' => now()->subHours(30),
        ], $this->apoteker);

        $dijawab = $this->pio->answer($permintaan, 'Disesuaikan klirens kreatinin.', [
            'reference' => 'Sanford Guide 2025',
        ], $this->apoteker);

        // Tidak ada kolom penyampaian_jawaban: kategorinya diturunkan dari
        // dua timestamp yang sudah ada, jadi tidak bisa berbeda dari
        // keduanya.
        $this->assertSame('lebih-dari-24-jam', $dijawab->responseBracket());
        $this->assertGreaterThan(29, $dijawab->responseHours());
        $this->assertArrayNotHasKey('penyampaian_jawaban', $dijawab->getAttributes());
    }

    #[Test]
    public function jawaban_dalam_satu_jam_masuk_kategori_segera(): void
    {
        $permintaan = $this->pio->ask([
            'asker_name' => 'dr. Rangga', 'asker_kind' => 'petugas-kesehatan',
            'method' => 'lisan', 'question_kind' => 'kontraindikasi',
            'question' => 'Kontraindikasi ketorolak?',
        ], $this->apoteker);

        $dijawab = $this->pio->answer($permintaan, 'Ulkus peptikum aktif, gangguan ginjal berat.', [
            'reference' => 'Formularium RS 2026',
        ], $this->apoteker);

        $this->assertSame('segera', $dijawab->responseBracket());
    }

    #[Test]
    public function rekap_kecepatan_jawaban_dihitung_dari_timestamp_tiap_baris(): void
    {
        $this->jawab('dosis', now()->subMinutes(20));
        $this->jawab('dosis', now()->subHours(5));
        $this->jawab('dosis', now()->subDays(3));

        $rekap = $this->pio->responseRecap(now()->subDays(7)->toDateTimeString(), now()->toDateTimeString());

        $this->assertSame(1, $rekap['segera']);
        $this->assertSame(1, $rekap['dalam-24-jam']);
        $this->assertSame(1, $rekap['lebih-dari-24-jam']);
    }

    #[Test]
    public function pertanyaan_belum_dijawab_bisa_ditagih(): void
    {
        $permintaan = $this->pio->ask([
            'asker_name' => 'dr. Rangga', 'asker_kind' => 'petugas-kesehatan',
            'method' => 'tertulis', 'question_kind' => 'efek-samping-obat',
            'question' => 'Efek samping jangka panjang?',
        ], $this->apoteker);

        $this->assertContains($permintaan->id, $this->pio->unanswered()->pluck('id')->all());

        $this->pio->answer($permintaan, 'Dijelaskan.', ['reference' => 'BNF 2026'], $this->apoteker);

        $this->assertNotContains($permintaan->id, $this->pio->unanswered()->pluck('id')->all());
    }

    #[Test]
    public function basis_data_menolak_jawaban_mendahului_pertanyaan(): void
    {
        $permintaan = $this->pio->ask([
            'asker_name' => 'dr. Rangga', 'asker_kind' => 'petugas-kesehatan',
            'method' => 'lisan', 'question_kind' => 'dosis', 'question' => 'Dosis?',
        ], $this->apoteker);

        $this->expectException(QueryException::class);

        DrugInformationRequest::query()->whereKey($permintaan->id)
            ->update(['answered_at' => now()->subDay()]);
    }

    #[Test]
    public function basis_data_menolak_status_dijawab_tanpa_jawaban(): void
    {
        $permintaan = $this->pio->ask([
            'asker_name' => 'dr. Rangga', 'asker_kind' => 'petugas-kesehatan',
            'method' => 'lisan', 'question_kind' => 'dosis', 'question' => 'Dosis?',
        ], $this->apoteker);

        $this->expectException(QueryException::class);

        DrugInformationRequest::query()->whereKey($permintaan->id)
            ->update(['status' => DrugInformationRequest::DIJAWAB, 'answered_at' => now()]);
    }

    #[Test]
    public function pertanyaan_yang_sudah_dijawab_tidak_bisa_dijawab_lagi(): void
    {
        $permintaan = $this->pio->ask([
            'asker_name' => 'dr. Rangga', 'asker_kind' => 'petugas-kesehatan',
            'method' => 'lisan', 'question_kind' => 'dosis', 'question' => 'Dosis?',
        ], $this->apoteker);

        $this->pio->answer($permintaan, 'Jawaban pertama.', ['reference' => 'BNF 2026'], $this->apoteker);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/sudah dijawab/');

        $this->pio->answer($permintaan->refresh(), 'Jawaban kedua.', ['reference' => 'BNF 2026'], $this->apoteker);
    }

    // ---------------------------------------------------------------- fixture

    private function jawab(string $jenis, Carbon $ditanya): DrugInformationRequest
    {
        $permintaan = $this->pio->ask([
            'asker_name' => 'dr. Rangga', 'asker_kind' => 'petugas-kesehatan',
            'method' => 'tertulis', 'question_kind' => $jenis,
            'question' => 'Pertanyaan uji.', 'asked_at' => $ditanya,
        ], $this->apoteker);

        return $this->pio->answer($permintaan, 'Jawaban uji.', ['reference' => 'Pustaka uji'], $this->apoteker);
    }

    private function resepDiserahkan(Registration $kunjungan): Prescription
    {
        $obat = Drug::query()->where('code', 'OBT-001')->firstOrFail();
        $depo = StockLocation::query()->where('code', 'DEPO-RJ')->firstOrFail();

        app(StockLedger::class)->receive(
            $obat->id, $depo->id, 'BATCH-KONSELING-'.uniqid(), 50,
            now()->addYear()->toDateString(), 1500, $this->apoteker
        );

        $resep = app(PrescriptionService::class)->create($kunjungan->id, $this->apoteker);

        app(PrescriptionService::class)->addItem($resep, $obat->id, 2, '2x1 tablet');
        app(PrescriptionService::class)->submit($resep->refresh());
        app(PrescriptionService::class)->review($resep->refresh(), 'disetujui', null, $this->apoteker);

        return app(PrescriptionService::class)->dispense($resep->refresh(), $depo->id, $this->apoteker);
    }

    private function daftarkan(): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Farmasi Klinis '.$urut, 'sex' => 'L', 'birth_date' => '1968-04-04',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
            practitionerId: Practitioner::query()->where('is_active', true)->value('id'),
        );
    }
}
