<?php

namespace Tests\Feature\Philanthropy;

use App\Modules\Philanthropy\Models\Assessment;
use App\Modules\Philanthropy\Models\AssessmentCriterion;
use App\Modules\Philanthropy\Models\Disbursement;
use App\Modules\Philanthropy\Models\Recipient;
use App\Modules\Philanthropy\Services\AidEligibilityService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Kelayakan & penyaluran dana kesehatan (domain T).
 *
 * Yang dikunci:
 *
 * 1. ENAM BELAS KOSAKATA LAHIR KOSONG kecuali asnaf — diskresi amil tidak
 *    boleh ditebak, daftar yang ditetapkan di luar rumah sakit boleh disalin.
 * 2. TIDAK ADA PUTUSAN OTOMATIS — bobot dihitung, putusan tetap manusia.
 * 3. ALASAN WAJIB PADA KEDUA ARAH, termasuk yang meluluskan.
 * 4. LABEL & BOBOT DIBEKUKAN saat menjawab.
 * 5. PENYALURAN SELALU MENYEBUT PENERIMA & ASESMENNYA.
 * 6. ZAKAT HANYA UNTUK ASNAF.
 */
class AidEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private AidEligibilityService $bantuan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bantuan = app(AidEligibilityService::class);
    }

    // ------------------------------------------------------ kosakata

    #[Test]
    public function delapan_golongan_asnaf_diseed_kategori_lain_lahir_kosong(): void
    {
        $asnaf = AssessmentCriterion::query()
            ->where('category', AssessmentCriterion::KATEGORI_ASNAF)->get();

        // At-Taubah 60 menyebut delapan golongan; jumlahnya tidak bisa
        // berubah karena kebijakan rumah sakit.
        $this->assertCount(8, $asnaf);
        $this->assertTrue($asnaf->contains(fn ($a) => str_starts_with($a->name, 'Fakir')));
        $this->assertTrue($asnaf->contains(fn ($a) => str_starts_with($a->name, 'Ibnu Sabil')));

        /*
         * Kelima belas kategori lain SENGAJA kosong. Batas penghasilan dan
         * jenis dinding yang dianggap tidak layak adalah penilaian amil
         * RSP UI — menebaknya berarti menerbitkan kriteria kemiskinan resmi
         * yang tidak pernah disepakati siapa pun, lalu memakainya menolak
         * orang. Kekosongan ini diuji supaya ia tetap pilihan yang tercatat,
         * bukan pekerjaan yang terlupa.
         */
        $kosong = AssessmentCriterion::kategoriKosong();

        $this->assertCount(15, $kosong);
        $this->assertNotContains(AssessmentCriterion::KATEGORI_ASNAF, $kosong);
        $this->assertContains(AssessmentCriterion::KATEGORI_KEPEMILIKAN_RUMAH, $kosong);
    }

    #[Test]
    public function kepemilikan_rumah_punya_kategori_meski_khanza_tak_punya_tabelnya(): void
    {
        /*
         * `zis_kepemilikan_rumah_penerima_dankes` ada di menu Khanza tapi
         * tidak punya tabel di sik_schema.sql, dan kelas Java yang ditunjuk
         * menunya adalah kelas ATAP RUMAH — salinan yang lupa diganti. Jadi
         * pada Khanza, status kepemilikan rumah tidak pernah bisa disimpan.
         */
        $this->assertContains(
            AssessmentCriterion::KATEGORI_KEPEMILIKAN_RUMAH,
            AssessmentCriterion::KATEGORI
        );

        $kriteria = $this->kriteria(AssessmentCriterion::KATEGORI_KEPEMILIKAN_RUMAH, 'KPM-KONTRAK', 'Kontrak/sewa');

        $this->assertSame('kepemilikan-rumah', $kriteria->category);
    }

    // ------------------------------------------------------- asesmen

    #[Test]
    public function asesmen_lahir_belum_diputuskan_walau_putusan_diketik_pemanggil(): void
    {
        $penerima = $this->penerima();

        $asesmen = $this->bantuan->openAssessment($penerima, [
            'assessed_on' => '2026-09-01',
            'surveyor_name' => 'Amil A',

            // Diselundupkan dari formulir; harus diabaikan.
            'decision' => Assessment::PUTUSAN_LAYAK,
            'decision_reason' => 'sudah pasti layak',
            'decided_by_name' => 'entah siapa',
        ]);

        $this->assertSame(Assessment::PUTUSAN_BELUM, $asesmen->decision);
        $this->assertNull($asesmen->decision_reason);
        $this->assertNull($asesmen->decided_by_name);
        $this->assertFalse($asesmen->sudahDiputuskan());
    }

    #[Test]
    public function jawaban_harus_dari_kategori_yang_sedang_dijawab(): void
    {
        $asesmen = $this->asesmen();
        $atap = $this->kriteria(AssessmentCriterion::KATEGORI_ATAP_RUMAH, 'ATP-SENG', 'Seng');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('milik kategori atap-rumah');

        // "Atap seng" sebagai jawaban penghasilan akan terbaca wajar di
        // ringkasan sampai ada yang membuka satu per satu.
        $this->bantuan->answer($asesmen, AssessmentCriterion::KATEGORI_PENGHASILAN, $atap);
    }

    #[Test]
    public function jawaban_kosong_berbeda_dari_kategori_yang_tak_pernah_disentuh(): void
    {
        $asesmen = $this->asesmen();

        $this->bantuan->answer($asesmen, AssessmentCriterion::KATEGORI_DAPUR, null, 'penghuni tidak mengizinkan masuk');

        $asesmen->load('answers');

        // Barisnya ADA — surveyor menandai bahwa ia bertanya dan tidak
        // memperoleh jawaban. Itu keterangan yang berbeda dari diam.
        $this->assertCount(1, $asesmen->answers);
        $this->assertFalse($asesmen->answers->first()->terjawab());
        $this->assertSame('penghuni tidak mengizinkan masuk', $asesmen->answers->first()->note);

        // Tapi kategorinya tetap terhitung belum dijawab.
        $this->assertContains(AssessmentCriterion::KATEGORI_DAPUR, $asesmen->kategoriBelumDijawab());
    }

    #[Test]
    public function label_dan_bobot_dibekukan_saat_menjawab(): void
    {
        $asesmen = $this->asesmen();
        $kriteria = $this->kriteria(AssessmentCriterion::KATEGORI_DINDING_RUMAH, 'DND-BAMBU', 'Bambu/anyaman', 3);

        $this->bantuan->answer($asesmen, AssessmentCriterion::KATEGORI_DINDING_RUMAH, $kriteria);

        // Kriterianya diubah SETELAH asesmen menjawab.
        $kriteria->update(['name' => 'Bambu (revisi 2027)', 'weight' => 9]);

        $jawaban = $asesmen->answers()->first();

        // Bunyinya tidak ikut berubah: putusan atas nasib orang harus tetap
        // terbaca sebagaimana ia diambil.
        $this->assertSame('Bambu/anyaman', $jawaban->label);
        $this->assertSame(3, $jawaban->weight);
    }

    #[Test]
    public function total_bobot_null_kalau_tidak_ada_jawaban_berbobot(): void
    {
        $asesmen = $this->asesmen();
        $tanpaBobot = $this->kriteria(AssessmentCriterion::KATEGORI_TERNAK, 'TRN-NIHIL', 'Tidak punya ternak');

        $this->bantuan->answer($asesmen, AssessmentCriterion::KATEGORI_TERNAK, $tanpaBobot);
        $asesmen->load('answers');

        // Nol dan "tidak diskor sama sekali" adalah dua keadaan berbeda;
        // menyamakannya menampilkan skor 0 untuk asesmen yang memang tidak
        // pernah diberi bobot.
        $this->assertNull($asesmen->totalBobot());

        $berbobot = $this->kriteria(AssessmentCriterion::KATEGORI_LANTAI_RUMAH, 'LNT-TANAH', 'Tanah', 4);
        $this->bantuan->answer($asesmen, AssessmentCriterion::KATEGORI_LANTAI_RUMAH, $berbobot);

        $this->assertSame(4, $asesmen->fresh()->load('answers')->totalBobot());
    }

    // ------------------------------------------------------- putusan

    #[Test]
    public function putusan_wajib_beralasan_pada_kedua_arah(): void
    {
        foreach ([Assessment::PUTUSAN_LAYAK, Assessment::PUTUSAN_TIDAK_LAYAK] as $putusan) {
            $asesmen = $this->asesmen();

            try {
                $this->bantuan->decide($asesmen, $putusan, '   ', 'Ketua Panitia');
                $this->fail('Putusan "'.$putusan.'" tanpa alasan seharusnya ditolak.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('alasan', $e->getMessage());
            }
        }
    }

    #[Test]
    public function putusan_tidak_diblokir_oleh_kategori_yang_belum_dijawab(): void
    {
        $asesmen = $this->asesmen();

        /*
         * Survei rumah sering tidak lengkap karena alasan yang sah, dan
         * menahan bantuan atas nama kerapian formulir bukan tujuan
         * instrumen ini — berbeda dari ICRA, yang persyaratannya memang
         * barrier fisik yang harus terpasang sebelum proyek jalan.
         */
        $this->assertCount(16, $asesmen->kategoriBelumDijawab());

        $hasil = $this->bantuan->decide(
            $asesmen, Assessment::PUTUSAN_LAYAK,
            'Kondisi rumah sudah dilihat langsung; sisanya tidak sempat ditanyakan.',
            'Ketua Panitia', 500000
        );

        $this->assertSame(Assessment::PUTUSAN_LAYAK, $hasil->decision);
        $this->assertSame('500000.00', $hasil->recommended_amount);
    }

    #[Test]
    public function jawaban_terkunci_setelah_asesmen_diputuskan(): void
    {
        $asesmen = $this->asesmenLayak();
        $kriteria = $this->kriteria(AssessmentCriterion::KATEGORI_ELEKTRONIK, 'ELK-TV', 'Televisi');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Jawaban tidak bisa diubah');

        $this->bantuan->answer($asesmen, AssessmentCriterion::KATEGORI_ELEKTRONIK, $kriteria);
    }

    #[Test]
    public function asesmen_tidak_bisa_diputuskan_dua_kali(): void
    {
        $asesmen = $this->asesmenLayak();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sudah diputuskan');

        $this->bantuan->decide($asesmen, Assessment::PUTUSAN_TIDAK_LAYAK, 'berubah pikiran', 'Orang Lain');
    }

    #[Test]
    public function basis_data_menolak_putusan_tanpa_pemutus_walau_lewat_penulisan_langsung(): void
    {
        $asesmen = $this->asesmen();

        // Aturan yang sama ditegakkan CHECK constraint, bukan hanya service:
        // impor massal dan perbaikan data manual lewat di bawah service.
        $this->expectException(QueryException::class);

        $asesmen->update(['decision' => Assessment::PUTUSAN_LAYAK]);
    }

    // ---------------------------------------------------- penyaluran

    #[Test]
    public function dana_hanya_bisa_disalurkan_atas_asesmen_yang_layak(): void
    {
        $asesmen = $this->asesmen();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('diputuskan layak');

        $this->bantuan->disburse($asesmen, [
            'fund_source' => Disbursement::SUMBER_INFAK,
            'amount' => 100000,
            'purpose' => 'obat',
        ]);
    }

    #[Test]
    public function penyaluran_selalu_menyebut_penerima_dan_asesmennya(): void
    {
        $asesmen = $this->asesmenLayak();
        $orangLain = $this->penerima('Orang Lain');

        $salur = $this->bantuan->disburse($asesmen, [
            'fund_source' => Disbursement::SUMBER_INFAK,
            'amount' => 250000,
            'purpose' => 'Biaya obat rawat jalan',

            // Diselundupkan dari formulir: dana atas dasar asesmen seseorang
            // dicoba dikeluarkan atas nama orang lain.
            'recipient_id' => $orangLain->getKey(),
        ]);

        $this->assertSame($asesmen->recipient_id, $salur->recipient_id);
        $this->assertNotSame($orangLain->getKey(), $salur->recipient_id);
        $this->assertSame($asesmen->getKey(), $salur->assessment_id);
        $this->assertNotNull($salur->disbursement_number);
    }

    #[Test]
    public function zakat_menuntut_golongan_asnaf_sudah_ditetapkan(): void
    {
        $asesmen = $this->asesmenLayak();

        try {
            $this->bantuan->disburse($asesmen, [
                'fund_source' => Disbursement::SUMBER_ZAKAT,
                'amount' => 300000,
                'purpose' => 'Biaya kontrol',
            ]);
            $this->fail('Zakat tanpa golongan asnaf seharusnya ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('asnaf', $e->getMessage());
        }

        // Infak TIDAK terikat syarat itu — kalau ikut ditolak, bantuan
        // tertahan oleh syarat yang tidak berlaku baginya.
        $infak = $this->bantuan->disburse($asesmen, [
            'fund_source' => Disbursement::SUMBER_INFAK,
            'amount' => 300000,
            'purpose' => 'Biaya kontrol',
        ]);

        $this->assertSame('300000.00', $infak->amount);

        // Setelah golongannya ditetapkan, zakat bisa jalan.
        $fakir = AssessmentCriterion::query()->where('code', 'ASNAF-FAK')->firstOrFail();
        $this->bantuan->tetapkanAsnaf($asesmen->recipient, $fakir);

        $zakat = $this->bantuan->disburse($asesmen->fresh(), [
            'fund_source' => Disbursement::SUMBER_ZAKAT,
            'amount' => 300000,
            'purpose' => 'Biaya kontrol',
        ]);

        $this->assertSame(Disbursement::SUMBER_ZAKAT, $zakat->fund_source);
    }

    #[Test]
    public function golongan_asnaf_harus_dari_kategori_asnaf(): void
    {
        $penerima = $this->penerima();
        $bukanAsnaf = $this->kriteria(AssessmentCriterion::KATEGORI_ATAP_RUMAH, 'ATP-GENTENG', 'Genteng');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('kategori asnaf');

        // Tanpa penjagaan ini, id kriteria apa pun bisa masuk sebagai
        // golongan asnaf dan pemeriksaan kelayakan zakat meloloskan siapa saja.
        $this->bantuan->tetapkanAsnaf($penerima, $bukanAsnaf);
    }

    #[Test]
    public function penyaluran_nol_atau_negatif_ditolak(): void
    {
        $asesmen = $this->asesmenLayak();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('lebih dari nol');

        $this->bantuan->disburse($asesmen, [
            'fund_source' => Disbursement::SUMBER_SEDEKAH,
            'amount' => 0,
            'purpose' => 'apa saja',
        ]);
    }

    // --------------------------------------------------------- rekap

    #[Test]
    public function rekap_menjawab_pertanyaan_yang_tak_bisa_dijawab_ambil_dankes(): void
    {
        $satu = $this->asesmenLayak('Keluarga Satu');
        $dua = $this->asesmenLayak('Keluarga Dua');

        $this->bantuan->disburse($satu, ['fund_source' => Disbursement::SUMBER_INFAK, 'amount' => 100000, 'purpose' => 'obat', 'disbursed_on' => '2026-09-01']);
        $this->bantuan->disburse($satu, ['fund_source' => Disbursement::SUMBER_INFAK, 'amount' => 150000, 'purpose' => 'obat lanjutan', 'disbursed_on' => '2026-09-05']);
        $this->bantuan->disburse($dua, ['fund_source' => Disbursement::SUMBER_CSR, 'amount' => 500000, 'purpose' => 'operasi', 'disbursed_on' => '2026-09-07']);

        $rekap = $this->bantuan->rekapSumber('2026-09-01', '2026-09-30');

        $this->assertSame('250000.00', $rekap['infak']['jumlah']);
        $this->assertSame(1, $rekap['infak']['penerima']);
        $this->assertSame(2, $rekap['infak']['penyaluran']);
        $this->assertSame('500000.00', $rekap['csr']['jumlah']);

        /*
         * Penerima berulang: bukan tuduhan, melainkan satu-satunya tempat
         * penerimaan ganda bisa terlihat — dan pada Khanza ia tidak bisa
         * terlihat sama sekali karena `ambil_dankes` tidak mencatat
         * penerimanya.
         */
        $berulang = $this->bantuan->penerimaBerulang('2026-09-01', '2026-09-30');

        $this->assertCount(1, $berulang);
        $this->assertSame('Keluarga Satu', $berulang[0]['nama']);
        $this->assertSame(2, $berulang[0]['kali']);
        $this->assertSame('250000.00', $berulang[0]['jumlah']);

        // Kolomnya numeric(16,2), jadi penjumlahannya kembali berskala dua.
        $this->assertSame('250000.00', $satu->recipient->totalDiterima());
        $this->assertSame('250000.00', $satu->recipient->totalDiterima(Disbursement::SUMBER_INFAK));
        $this->assertSame('0', $satu->recipient->totalDiterima(Disbursement::SUMBER_ZAKAT));
    }

    // ------------------------------------------------------- pembantu

    private function penerima(string $nama = 'Keluarga Uji'): Recipient
    {
        return $this->bantuan->registerRecipient([
            'name' => $nama,
            'address' => 'Kampung Uji RT 01',
        ]);
    }

    private function asesmen(string $nama = 'Keluarga Uji'): Assessment
    {
        return $this->bantuan->openAssessment($this->penerima($nama), [
            'assessed_on' => '2026-09-01',
            'surveyor_name' => 'Amil Survei',
        ]);
    }

    private function asesmenLayak(string $nama = 'Keluarga Uji'): Assessment
    {
        return $this->bantuan->decide(
            $this->asesmen($nama),
            Assessment::PUTUSAN_LAYAK,
            'Rumah tidak layak huni dan tidak ada penghasilan tetap.',
            'Ketua Panitia ZIS'
        );
    }

    private function kriteria(string $kategori, string $kode, string $nama, ?int $bobot = null): AssessmentCriterion
    {
        return AssessmentCriterion::query()->create([
            'category' => $kategori,
            'code' => $kode,
            'name' => $nama,
            'weight' => $bobot,
            'is_active' => true,
        ]);
    }
}
