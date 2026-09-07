<?php

namespace Tests\Feature\Clinical;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Clinical\Models\Delivery;
use App\Modules\Clinical\Models\DeliveryBaby;
use App\Modules\Clinical\Models\DeliveryBabyApgarScore;
use App\Modules\Clinical\Services\ClinicalException;
use App\Modules\Clinical\Services\DeliveryService;
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
 * Persalinan & bayi baru lahir (domain M item J).
 *
 * Yang paling perlu dikunci:
 *
 * 1. BAYI KEDUA PUNYA TEMPAT. Ini kegagalan Khanza yang paling berat
 *    sejauh ini: catatan_persalinan hanya menyediakan satu kolom untuk
 *    tiap hal tentang bayi, sehingga kelahiran kembar kehilangan bayi
 *    keduanya tanpa pesan apa pun.
 * 2. APGAR TERSIMPAN SEBAGAI LIMA KOMPONEN, jumlahnya dihitung —
 *    Khanza menjejalkannya ke satu varchar(20).
 * 3. JUMLAH PERDARAHAN DIHITUNG, tidak disimpan; ambang 500 mL
 *    diputuskan dari angka itu.
 * 4. NOL ADALAH NILAI APGAR YANG SAH, dan justru yang paling
 *    menentukan tindakan.
 */
class DeliveryTest extends TestCase
{
    use RefreshDatabase;

    private DeliveryService $persalinan;

    private User $bidan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->persalinan = app(DeliveryService::class);

        $this->bidan = User::query()->create([
            'username' => 'uji-persalinan', 'name' => 'Bd. Sri Lestari',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    // ================================================== bayi adalah baris

    #[Test]
    public function kelahiran_kembar_mencatat_kedua_bayinya(): void
    {
        $catatan = $this->buka();

        $pertama = $this->persalinan->addBaby($catatan, [
            'sex' => 'L', 'weight_grams' => 2400, 'length_cm' => 45.0,
        ], $this->bidan);

        // Inilah bayi yang tidak punya tempat di Khanza.
        $kedua = $this->persalinan->addBaby($catatan, [
            'sex' => 'P', 'weight_grams' => 2250, 'length_cm' => 44.0,
        ], $this->bidan);

        $this->assertSame(1, $pertama->birth_order);
        $this->assertSame(2, $kedua->birth_order);
        $this->assertCount(2, $catatan->refresh()->babies);
    }

    #[Test]
    public function kembar_tiga_pun_muat(): void
    {
        $catatan = $this->buka();

        foreach (['L', 'P', 'L'] as $jenis) {
            $this->persalinan->addBaby($catatan, ['sex' => $jenis, 'weight_grams' => 1800], $this->bidan);
        }

        $this->assertSame([1, 2, 3], $catatan->refresh()->babies->pluck('birth_order')->all());
    }

    #[Test]
    public function urutan_lahir_tidak_boleh_kembar_dua_kali(): void
    {
        $catatan = $this->buka();
        $this->persalinan->addBaby($catatan, ['sex' => 'L'], $this->bidan);

        $this->expectException(QueryException::class);

        $this->persalinan->addBaby($catatan, ['sex' => 'P', 'birth_order' => 1], $this->bidan);
    }

    #[Test]
    public function jenis_kelamin_tidak_jelas_adalah_temuan_yang_sah(): void
    {
        $catatan = $this->buka();

        // Ambiguitas genital adalah temuan nyata yang harus bisa dicatat,
        // bukan dipaksa jadi salah satu dari dua pilihan.
        $bayi = $this->persalinan->addBaby($catatan, ['sex' => 'tidak-jelas'], $this->bidan);

        $this->assertSame('tidak-jelas', $bayi->sex);
    }

    #[Test]
    public function ukuran_bayi_tersimpan_sebagai_angka_bukan_teks(): void
    {
        $catatan = $this->buka();

        $bayi = $this->persalinan->addBaby($catatan, [
            'sex' => 'L', 'weight_grams' => 2400, 'head_circumference_cm' => 33.5,
        ], $this->bidan);

        // pasien_bayi Khanza menyimpan berat_badan varchar(10); ukuran yang
        // berupa teks tidak bisa dibandingkan dengan kurva pertumbuhan.
        $this->assertSame(2400, $bayi->weight_grams);
        $this->assertTrue($bayi->isLowBirthWeight());
    }

    #[Test]
    public function berat_yang_belum_ditimbang_bukan_berarti_beratnya_cukup(): void
    {
        $catatan = $this->buka();
        $bayi = $this->persalinan->addBaby($catatan, ['sex' => 'L'], $this->bidan);

        $this->assertNull($bayi->isLowBirthWeight());
    }

    // ========================================================== apgar

    #[Test]
    public function apgar_tersimpan_sebagai_lima_komponen_dan_jumlahnya_dihitung(): void
    {
        $bayi = $this->bayiHidup();

        $skor = $this->persalinan->recordApgar($bayi, 1, [
            'appearance' => 1, 'pulse' => 2, 'grimace' => 1,
            'activity' => 1, 'respiration' => 2,
        ], $this->bidan);

        $this->assertSame(7, $skor->total());
        $this->assertSame('normal', $skor->interpretation());

        // Tidak ada kolom jumlah: yang tersimpan hanya komponennya.
        $this->assertArrayNotHasKey('total', $skor->getAttributes());
    }

    #[Test]
    public function komponen_yang_lemah_bisa_disebutkan(): void
    {
        $bayi = $this->bayiHidup();

        $skor = $this->persalinan->recordApgar($bayi, 1, [
            'appearance' => 0, 'pulse' => 2, 'grimace' => 1,
            'activity' => 2, 'respiration' => 0,
        ], $this->bidan);

        // Inilah yang hilang saat APGAR disimpan sebagai satu angka:
        // komponen mana yang rendah menentukan tindakan resusitasi.
        $lemah = $skor->weakComponents();

        $this->assertContains('Warna kulit', $lemah);
        $this->assertContains('Usaha napas', $lemah);
        $this->assertContains('Refleks terhadap rangsang', $lemah);
        $this->assertNotContains('Denyut jantung', $lemah);
    }

    #[Test]
    public function nol_adalah_nilai_apgar_yang_sah(): void
    {
        $bayi = $this->bayiHidup();

        // Kalau kode memakai empty() alih-alih memeriksa keberadaan kunci,
        // penilaian ini akan ditolak seolah belum diisi — padahal justru
        // inilah bayi yang paling perlu tercatat.
        $skor = $this->persalinan->recordApgar($bayi, 1, [
            'appearance' => 0, 'pulse' => 0, 'grimace' => 0,
            'activity' => 0, 'respiration' => 0,
        ], $this->bidan);

        $this->assertSame(0, $skor->total());
        $this->assertSame('asfiksia-berat', $skor->interpretation());
    }

    #[Test]
    public function apgar_dengan_komponen_kurang_ditolak(): void
    {
        $bayi = $this->bayiHidup();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/respiration/');

        $this->persalinan->recordApgar($bayi, 1, [
            'appearance' => 2, 'pulse' => 2, 'grimace' => 2, 'activity' => 2,
        ], $this->bidan);
    }

    #[Test]
    public function nilai_komponen_di_luar_nol_sampai_dua_ditolak(): void
    {
        $bayi = $this->bayiHidup();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/0, 1, atau 2/');

        $this->persalinan->recordApgar($bayi, 1, [
            'appearance' => 3, 'pulse' => 2, 'grimace' => 2,
            'activity' => 2, 'respiration' => 2,
        ], $this->bidan);
    }

    #[Test]
    public function menit_di_luar_menit_baku_ditolak(): void
    {
        $bayi = $this->bayiHidup();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/bukan menit baku APGAR/');

        $this->persalinan->recordApgar($bayi, 3, $this->apgarPenuh(), $this->bidan);
    }

    #[Test]
    public function bayi_lahir_mati_tidak_dinilai_apgar(): void
    {
        $catatan = $this->buka();
        $bayi = $this->persalinan->addBaby($catatan, [
            'sex' => 'P', 'birth_status' => DeliveryBaby::LAHIR_MATI,
        ], $this->bidan);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/mengarang angka untuk bayi yang tidak bernapas/');

        $this->persalinan->recordApgar($bayi, 1, $this->apgarPenuh(), $this->bidan);
    }

    #[Test]
    public function menilai_ulang_menit_yang_sama_memperbarui_bukan_menggandakan(): void
    {
        $bayi = $this->bayiHidup();

        $this->persalinan->recordApgar($bayi, 1, $this->apgarPenuh(1), $this->bidan);
        $ralat = $this->persalinan->recordApgar($bayi, 1, $this->apgarPenuh(2), $this->bidan);

        $this->assertSame(10, $ralat->total());
        $this->assertCount(1, $bayi->refresh()->apgarScores);
    }

    #[Test]
    public function basis_data_menolak_komponen_di_luar_rentang(): void
    {
        $bayi = $this->bayiHidup();
        $skor = $this->persalinan->recordApgar($bayi, 1, $this->apgarPenuh(), $this->bidan);

        $this->expectException(QueryException::class);

        DeliveryBabyApgarScore::query()->whereKey($skor->id)->update(['pulse' => 5]);
    }

    // ============================================== nilai turunan dihitung

    #[Test]
    public function jumlah_perdarahan_dihitung_dari_kala_bukan_disimpan(): void
    {
        $catatan = $this->buka();

        $terisi = $this->persalinan->save($catatan, [
            'blood_loss_stage2_ml' => 150,
            'blood_loss_stage3_ml' => 250,
            'blood_loss_stage4_ml' => 150,
        ]);

        $this->assertSame(550, $terisi->bloodLossMl());
        $this->assertTrue($terisi->isPostpartumHaemorrhage());

        // Khanza menyediakan darah_keluar_jumlah di samping bagiannya;
        // di sini tidak ada kolom yang bisa berbeda dari penjumlahannya.
        $this->assertArrayNotHasKey('blood_loss_total_ml', $terisi->getAttributes());
    }

    #[Test]
    public function perdarahan_yang_belum_dicatat_bukan_berarti_tidak_ada(): void
    {
        $catatan = $this->buka();

        $this->assertNull($catatan->bloodLossMl());
        $this->assertNull($catatan->isPostpartumHaemorrhage());
    }

    #[Test]
    public function perdarahan_di_bawah_ambang_bukan_perdarahan_pascasalin(): void
    {
        $catatan = $this->persalinan->save($this->buka(), [
            'blood_loss_stage2_ml' => 100, 'blood_loss_stage3_ml' => 150,
        ]);

        $this->assertSame(250, $catatan->bloodLossMl());
        $this->assertFalse($catatan->isPostpartumHaemorrhage());
    }

    #[Test]
    public function lama_persalinan_dihitung_dari_kala(): void
    {
        $catatan = $this->persalinan->save($this->buka(), [
            'stage1_minutes' => 480, 'stage2_minutes' => 45, 'stage3_minutes' => 15,
        ]);

        $this->assertSame(540, $catatan->labourMinutes());
        $this->assertArrayNotHasKey('labour_total_minutes', $catatan->getAttributes());
    }

    #[Test]
    public function selisih_pecah_ketuban_bisa_dihitung(): void
    {
        $catatan = $this->persalinan->save($this->buka(), [
            'membrane_ruptured_at' => now()->subHours(20),
            'ended_at' => now(),
        ]);

        // Khanza memecahnya jadi jam dan menit dalam dua kolom teks yang
        // tidak bisa dikurangkan; justru selisih inilah yang menentukan
        // risiko infeksi.
        $this->assertGreaterThan(19, $catatan->membraneRuptureHours());
        $this->assertLessThan(21, $catatan->membraneRuptureHours());
    }

    #[Test]
    public function persalinan_dengan_perdarahan_bisa_ditagih_sepanjang_periode(): void
    {
        $berdarah = $this->persalinan->save($this->buka(), [
            'blood_loss_stage2_ml' => 400, 'blood_loss_stage3_ml' => 200,
        ]);
        $aman = $this->persalinan->save($this->buka(), ['blood_loss_stage2_ml' => 120]);

        $daftar = $this->persalinan
            ->withHaemorrhage(now()->subDay()->toDateTimeString(), now()->addDay()->toDateTimeString())
            ->pluck('id')->all();

        $this->assertContains($berdarah->id, $daftar);
        $this->assertNotContains($aman->id, $daftar);
    }

    // ========================================================= finalisasi

    #[Test]
    public function persalinan_tanpa_bayi_tidak_bisa_difinalkan(): void
    {
        $catatan = $this->persalinan->save($this->buka(), ['ended_at' => now()]);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/tanpa bayi tidak menjelaskan apa yang terjadi/');

        $this->persalinan->finalize($catatan, $this->bidan);
    }

    #[Test]
    public function bayi_lahir_hidup_wajib_punya_apgar_menit_1_dan_5(): void
    {
        $catatan = $this->persalinan->save($this->buka(), ['ended_at' => now()]);
        $bayi = $this->persalinan->addBaby($catatan, ['sex' => 'L'], $this->bidan);

        $this->persalinan->recordApgar($bayi, 1, $this->apgarPenuh(), $this->bidan);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/bayi ke-1 menit 5/');

        $this->persalinan->finalize($catatan->refresh(), $this->bidan);
    }

    #[Test]
    public function tiap_bayi_kembar_dinilai_sendiri_sendiri(): void
    {
        $catatan = $this->persalinan->save($this->buka(), ['ended_at' => now()]);

        $pertama = $this->persalinan->addBaby($catatan, ['sex' => 'L'], $this->bidan);
        $this->persalinan->addBaby($catatan, ['sex' => 'P'], $this->bidan);

        $this->persalinan->recordApgar($pertama, 1, $this->apgarPenuh(), $this->bidan);
        $this->persalinan->recordApgar($pertama, 5, $this->apgarPenuh(), $this->bidan);

        // Bayi kedua belum dinilai — dan justru itu yang tidak akan pernah
        // ketahuan pada sistem yang tidak punya tempat untuknya.
        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/bayi ke-2 menit 1/');

        $this->persalinan->finalize($catatan->refresh(), $this->bidan);
    }

    #[Test]
    public function bayi_lahir_mati_tidak_menahan_finalisasi(): void
    {
        $catatan = $this->persalinan->save($this->buka(), ['ended_at' => now()]);
        $this->persalinan->addBaby($catatan, [
            'sex' => 'P', 'birth_status' => DeliveryBaby::LAHIR_MATI, 'weight_grams' => 1200,
        ], $this->bidan);

        $final = $this->persalinan->finalize($catatan->refresh(), $this->bidan);

        $this->assertSame(Delivery::FINAL, $final->status);
    }

    #[Test]
    public function persalinan_lengkap_bisa_difinalkan(): void
    {
        $catatan = $this->persalinan->save($this->buka(), [
            'ended_at' => now(), 'delivery_method' => 'spontan', 'perineum' => 'episiotomi',
        ]);

        $bayi = $this->persalinan->addBaby($catatan, ['sex' => 'L', 'weight_grams' => 3100], $this->bidan);
        $this->persalinan->recordApgar($bayi, 1, $this->apgarPenuh(), $this->bidan);
        $this->persalinan->recordApgar($bayi, 5, $this->apgarPenuh(2), $this->bidan);

        $final = $this->persalinan->finalize($catatan->refresh(), $this->bidan);

        $this->assertSame(Delivery::FINAL, $final->status);
        $this->assertNotNull($final->finalized_at);
    }

    #[Test]
    public function persalinan_tanpa_waktu_selesai_tidak_bisa_difinalkan(): void
    {
        $catatan = $this->buka();
        $bayi = $this->persalinan->addBaby($catatan, ['sex' => 'L'], $this->bidan);
        $this->persalinan->recordApgar($bayi, 1, $this->apgarPenuh(), $this->bidan);
        $this->persalinan->recordApgar($bayi, 5, $this->apgarPenuh(), $this->bidan);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/Waktu selesai persalinan wajib diisi/');

        $this->persalinan->finalize($catatan->refresh(), $this->bidan);
    }

    #[Test]
    public function persalinan_final_tidak_bisa_ditambahi_bayi(): void
    {
        $catatan = $this->persalinanFinal();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/tidak bisa diubah/');

        $this->persalinan->addBaby($catatan, ['sex' => 'P'], $this->bidan);
    }

    #[Test]
    public function cara_persalinan_di_luar_kosakata_ditolak(): void
    {
        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches("/'lompat' tidak dikenali/");

        $this->persalinan->save($this->buka(), ['delivery_method' => 'lompat']);
    }

    // ================================================== tautan rekam medis

    #[Test]
    public function bayi_bisa_ditautkan_ke_rekam_medisnya_sendiri(): void
    {
        $bayi = $this->bayiHidup();

        $pasienBayi = app(PatientRegistry::class)->register([
            'name' => 'Bayi Ny. Uji', 'sex' => 'L', 'birth_date' => now()->toDateString(),
        ]);

        $tertaut = $this->persalinan->linkPatient($bayi, $pasienBayi->id, $pasienBayi->medical_record_number);

        $this->assertSame($pasienBayi->id, $tertaut->patient_id);
        $this->assertSame($pasienBayi->medical_record_number, $tertaut->patient_mrn);
    }

    #[Test]
    public function bayi_lahir_mati_tidak_didaftarkan_sebagai_pasien(): void
    {
        $catatan = $this->buka();
        $bayi = $this->persalinan->addBaby($catatan, [
            'sex' => 'P', 'birth_status' => DeliveryBaby::LAHIR_MATI,
        ], $this->bidan);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/surat keterangan lahir mati/');

        $this->persalinan->linkPatient($bayi, 999, 'RM-999');
    }

    // ---------------------------------------------------------------- fixture

    /**
     * @return array<string, int>
     */
    private function apgarPenuh(int $nilai = 2): array
    {
        return [
            'appearance' => $nilai, 'pulse' => $nilai, 'grimace' => $nilai,
            'activity' => $nilai, 'respiration' => $nilai,
        ];
    }

    private function persalinanFinal(): Delivery
    {
        $catatan = $this->persalinan->save($this->buka(), ['ended_at' => now()]);
        $bayi = $this->persalinan->addBaby($catatan, ['sex' => 'L'], $this->bidan);

        $this->persalinan->recordApgar($bayi, 1, $this->apgarPenuh(), $this->bidan);
        $this->persalinan->recordApgar($bayi, 5, $this->apgarPenuh(), $this->bidan);

        return $this->persalinan->finalize($catatan->refresh(), $this->bidan);
    }

    private function bayiHidup(): DeliveryBaby
    {
        return $this->persalinan->addBaby($this->buka(), [
            'sex' => 'L', 'weight_grams' => 2400,
        ], $this->bidan);
    }

    private function buka(): Delivery
    {
        return $this->persalinan->open($this->daftarkan()->id, [], $this->bidan);
    }

    private function daftarkan(): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Ny. Bersalin '.$urut, 'sex' => 'P', 'birth_date' => '1995-02-02',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-OBGYN')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
            practitionerId: Practitioner::query()->where('is_active', true)->value('id'),
        );
    }
}
