<?php

namespace Tests\Feature\Integration;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Clinical\Models\Diagnosis;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Integration\Models\BpjsPharmacyService;
use App\Modules\Integration\Models\PrbEnrollment;
use App\Modules\Integration\Services\Bpjs\PrbService;
use App\Modules\Integration\Services\Bpjs\SepService;
use App\Modules\Integration\Services\IdentityMappingService;
use App\Modules\Integration\Services\IntegrationException;
use App\Modules\Integration\Services\PayerReferenceService;
use App\Modules\Organization\Models\Unit;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Program Rujuk Balik & pelayanan obat apotek BPJS (domain L item G).
 *
 * Yang paling perlu dikunci:
 *
 * 1. CALON DIHITUNG DARI DATA KITA SENDIRI — diagnosis kronis yang termasuk
 *    daftar PRB ditambah kunjungan berulang, bukan menunggu BPJS menyebut
 *    siapa calonnya.
 * 2. PENOLAKAN ADALAH CATATAN, BUKAN KEKOSONGAN — "belum ditawarkan" harus
 *    tetap bisa dibedakan dari "sudah ditawarkan dan ditolak".
 * 3. BATAS HARI OBAT DITEGAKKAN SAAT PENCATATAN, bukan saat klaim: ditolak
 *    setelah obatnya diserahkan berarti rumah sakit menanggung sendiri.
 */
class PrbTest extends TestCase
{
    use RefreshDatabase;

    /** Diagnosis kronis yang benar-benar ada di daftar PRB BPJS. */
    private const DM = 'E11.9';
    private const HIPERTENSI = 'I10';

    private PrbService $prb;
    private SepService $sep;
    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->prb = app(PrbService::class);
        $this->sep = app(SepService::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-prb', 'name' => 'Petugas PRB Uji', 'password' => 'password', 'is_active' => true,
        ]);

        // Daftar diagnosis PRB adalah referensi milik BPJS (item E), bukan
        // master kita — jadi diisi lewat mekanisme yang sama.
        app(PayerReferenceService::class)->refresh('bpjs', PrbService::REFERENSI_DIAGNOSA, [
            ['code' => self::DM, 'name' => 'Diabetes Melitus Tipe 2'],
            ['code' => self::HIPERTENSI, 'name' => 'Hipertensi Esensial'],
        ]);

        app(IdentityMappingService::class)->setManually(
            'bpjs', 'poli', 'organization',
            Unit::query()->where('code', 'POL-UMUM')->value('id'),
            'RJ0001', $this->petugas->id
        );
    }

    // ---------------------------------------------------------------- calon

    /**
     * INTI ITEM INI: calon PRB muncul dari data kita sendiri — dua kunjungan
     * dengan diagnosis kronis yang termasuk daftar PRB, tanpa BPJS perlu
     * memberi tahu siapa pun.
     */
    #[Test]
    public function calon_muncul_dari_diagnosis_kronis_dan_kunjungan_berulang(): void
    {
        $pasien = $this->pasien('Pasien Kronis');
        $this->kunjunganBerdiagnosis($pasien, self::DM, mundurBulan: $this->bulanKe());
        $this->kunjunganBerdiagnosis($pasien, self::DM, mundurBulan: $this->bulanKe());

        $calon = $this->prb->candidates();

        $this->assertCount(1, $calon);
        $this->assertSame(self::DM, $calon->first()->diagnosis_code);
        $this->assertSame(2, (int) $calon->first()->kunjungan);
        $this->assertSame('0001234567890', $calon->first()->card_number);
    }

    /** Sekali berkunjung belum menandakan penyakit kronis yang rutin. */
    #[Test]
    public function kunjungan_sekali_belum_jadi_calon(): void
    {
        $pasien = $this->pasien('Pasien Sekali Datang');
        $this->kunjunganBerdiagnosis($pasien, self::DM, mundurBulan: $this->bulanKe());

        $this->assertCount(0, $this->prb->candidates());
    }

    /**
     * Kepesertaan BPJS dibuktikan SEP yang terbit, bukan ditebak: nomor
     * kartunya memang hanya ada di sana, dan PRB tanpa nomor kartu tidak
     * bisa didaftarkan.
     */
    #[Test]
    public function pasien_tanpa_sep_bpjs_tidak_jadi_calon(): void
    {
        $pasien = $this->pasien('Pasien Umum');
        $this->kunjunganBerdiagnosis($pasien, self::DM, terbitkanSep: false, mundurBulan: $this->bulanKe());
        $this->kunjunganBerdiagnosis($pasien, self::DM, terbitkanSep: false, mundurBulan: $this->bulanKe());

        $this->assertCount(0, $this->prb->candidates());
    }

    /** Diagnosis kronis di luar daftar PRB bukan urusan program ini. */
    #[Test]
    public function diagnosis_di_luar_daftar_prb_tidak_jadi_calon(): void
    {
        $pasien = $this->pasien('Pasien Diagnosis Lain');
        $this->kunjunganBerdiagnosis($pasien, 'J45.9', mundurBulan: $this->bulanKe());
        $this->kunjunganBerdiagnosis($pasien, 'J45.9', mundurBulan: $this->bulanKe());

        $this->assertCount(0, $this->prb->candidates());
    }

    /** Daftar calon gunanya menunjukkan siapa yang BELUM disentuh. */
    #[Test]
    public function calon_yang_sudah_ditawarkan_tidak_muncul_lagi(): void
    {
        $pasien = $this->pasien('Pasien Sudah Ditawari');
        $this->kunjunganBerdiagnosis($pasien, self::DM, mundurBulan: $this->bulanKe());
        $this->kunjunganBerdiagnosis($pasien, self::DM, mundurBulan: $this->bulanKe());

        $calon = $this->prb->candidates()->first();
        $this->prb->offer((array) $calon + ['patient_name' => 'Pasien Sudah Ditawari'], $this->petugas->id);

        $this->assertCount(0, $this->prb->candidates());
    }

    /** Referensi diagnosis PRB belum diambil berarti belum ada calon apa pun. */
    #[Test]
    public function tanpa_referensi_diagnosa_prb_daftar_calon_kosong(): void
    {
        \App\Modules\Integration\Models\PayerReference::query()
            ->where('reference_type', PrbService::REFERENSI_DIAGNOSA)->delete();

        $this->assertCount(0, $this->prb->candidates());
    }

    // ---------------------------------------------------------- pendaftaran

    #[Test]
    public function diagnosis_di_luar_daftar_prb_ditolak_saat_ditawarkan(): void
    {
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('tidak termasuk daftar PRB');

        $this->prb->offer($this->tawaran(['diagnosis_code' => 'J45.9']), $this->petugas->id);
    }

    #[Test]
    public function tawaran_tanpa_nomor_kartu_ditolak(): void
    {
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('Nomor kartu BPJS wajib');

        $this->prb->offer($this->tawaran(['card_number' => '']), $this->petugas->id);
    }

    #[Test]
    public function satu_peserta_tidak_boleh_dua_keikutsertaan_untuk_diagnosis_yang_sama(): void
    {
        $this->prb->offer($this->tawaran(), $this->petugas->id);

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('masih berjalan');

        $this->prb->offer($this->tawaran(), $this->petugas->id);
    }

    /** Diagnosis berbeda adalah program berbeda; keduanya sah berjalan. */
    #[Test]
    public function peserta_yang_sama_boleh_ikut_untuk_diagnosis_berbeda(): void
    {
        $this->prb->offer($this->tawaran(), $this->petugas->id);
        $kedua = $this->prb->offer($this->tawaran(['diagnosis_code' => self::HIPERTENSI]), $this->petugas->id);

        $this->assertSame(PrbEnrollment::DITAWARKAN, $kedua->status);
        $this->assertCount(2, $this->prb->members());
    }

    #[Test]
    public function pendaftaran_tanpa_apotek_atau_faskes_tujuan_ditolak(): void
    {
        $prb = $this->prb->offer($this->tawaran(), $this->petugas->id);

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('Apotek tujuan wajib');

        $this->prb->enroll($prb, ['fktp_code' => '0001U001']);
    }

    #[Test]
    public function pendaftaran_menyimpan_nomor_prb_dari_bpjs_dan_masa_berlakunya(): void
    {
        $prb = $this->prb->offer($this->tawaran(), $this->petugas->id);

        $terdaftar = $this->prb->enroll($prb, [
            'fktp_code' => '0001U001', 'fktp_name' => 'Puskesmas Uji',
            'pharmacy_code' => 'APT01', 'pharmacy_name' => 'Apotek Kimia Uji',
            'prb_number' => 'PRB-2026-0001',
            'valid_until' => now()->addMonths(3)->toDateString(),
        ]);

        $this->assertSame(PrbEnrollment::TERDAFTAR, $terdaftar->status);
        $this->assertSame('PRB-2026-0001', $terdaftar->prb_number);
        $this->assertNotNull($terdaftar->enrolled_on);
        $this->assertFalse($terdaftar->isExpired());
    }

    #[Test]
    public function hanya_yang_sudah_ditawarkan_yang_bisa_didaftarkan(): void
    {
        $prb = $this->prb->offer($this->tawaran(), $this->petugas->id);
        $this->prb->reject($prb, 'Pasien ingin tetap kontrol di rumah sakit.');

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('sudah ditawarkan');

        $this->prb->enroll($prb->refresh(), ['fktp_code' => '0001U001', 'pharmacy_code' => 'APT01']);
    }

    /**
     * ATURAN KEDUA: penolakan dicatat berikut alasannya. Dibiarkan kosong,
     * pasien yang sudah menolak akan ditawari lagi dan lagi.
     */
    #[Test]
    public function penolakan_dicatat_berikut_alasannya(): void
    {
        $prb = $this->prb->offer($this->tawaran(), $this->petugas->id);

        $ditolak = $this->prb->reject($prb, 'Apotek rujukan terlalu jauh dari rumah.');

        $this->assertSame(PrbEnrollment::DITOLAK, $ditolak->status);
        $this->assertSame('Apotek rujukan terlalu jauh dari rumah.', $ditolak->rejection_reason);
    }

    #[Test]
    public function penolakan_tanpa_alasan_ditolak(): void
    {
        $prb = $this->prb->offer($this->tawaran(), $this->petugas->id);

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('Alasan penolakan wajib');

        $this->prb->reject($prb, '   ');
    }

    /** Menolak sekarang bukan menolak selamanya — tahun depan boleh ditawari lagi. */
    #[Test]
    public function yang_sudah_menolak_boleh_ditawari_lagi(): void
    {
        $prb = $this->prb->offer($this->tawaran(), $this->petugas->id);
        $this->prb->reject($prb, 'Belum bersedia.');

        $baru = $this->prb->offer($this->tawaran(), $this->petugas->id);

        $this->assertSame(PrbEnrollment::DITAWARKAN, $baru->status);
        $this->assertCount(2, $this->prb->members());
    }

    #[Test]
    public function keikutsertaan_yang_sudah_berjalan_tidak_bisa_ditolak(): void
    {
        $prb = $this->prb->enroll(
            $this->prb->offer($this->tawaran(), $this->petugas->id),
            ['fktp_code' => '0001U001', 'pharmacy_code' => 'APT01']
        );

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('sebelum keikutsertaan berjalan');

        $this->prb->reject($prb, 'Terlambat menolak.');
    }

    /**
     * Surat PRB berlaku terbatas. Tanpa daftar ini, pasien baru tahu masa
     * berlakunya habis saat obatnya ditolak di apotek.
     */
    #[Test]
    public function keikutsertaan_yang_lewat_tempo_muncul_di_daftar_kedaluwarsa(): void
    {
        $prb = $this->prb->enroll(
            $this->prb->offer($this->tawaran(), $this->petugas->id),
            [
                'fktp_code' => '0001U001', 'pharmacy_code' => 'APT01',
                'valid_until' => now()->subDay()->toDateString(),
            ]
        );

        $kedaluwarsa = $this->prb->expired();

        $this->assertCount(1, $kedaluwarsa);
        $this->assertSame($prb->id, $kedaluwarsa->first()->id);
        $this->assertTrue($kedaluwarsa->first()->isExpired());
    }

    // ------------------------------------------------------- pelayanan obat

    /**
     * ATURAN KETIGA: batas hari ditegakkan saat pencatatan. Ditolak setelah
     * obatnya diserahkan berarti rumah sakit menanggung sendiri.
     */
    #[Test]
    public function obat_prb_lebih_dari_30_hari_ditolak(): void
    {
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('dibatasi 30 hari');

        $this->prb->recordService($this->pelayanan(['day_supply' => 31]), $this->petugas->id);
    }

    #[Test]
    public function obat_kronis_lebih_dari_23_hari_ditolak(): void
    {
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('dibatasi 23 hari');

        $this->prb->recordService(
            $this->pelayanan(['service_type' => BpjsPharmacyService::KRONIS, 'day_supply' => 24]),
            $this->petugas->id
        );
    }

    #[Test]
    public function obat_kronis_tepat_23_hari_diterima(): void
    {
        $layanan = $this->prb->recordService(
            $this->pelayanan(['service_type' => BpjsPharmacyService::KRONIS, 'day_supply' => 23]),
            $this->petugas->id
        );

        $this->assertSame(23, $layanan->day_supply);
    }

    #[Test]
    public function pelayanan_tanpa_sep_ditolak(): void
    {
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('Nomor SEP wajib');

        $this->prb->recordService($this->pelayanan(['sep_number' => null]), $this->petugas->id);
    }

    #[Test]
    public function jenis_pelayanan_asing_ditolak(): void
    {
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('tidak dikenal');

        $this->prb->recordService($this->pelayanan(['service_type' => 'vitamin']), $this->petugas->id);
    }

    /**
     * Rincian obat DIBEKUKAN: yang dilaporkan ke BPJS harus tetap sama
     * sekalipun harga di master farmasi berubah setelahnya.
     */
    #[Test]
    public function rincian_obat_dan_nilainya_dibekukan_saat_dicatat(): void
    {
        $layanan = $this->prb->recordService($this->pelayanan([
            'items' => [
                ['code' => 'OBT-01', 'name' => 'Metformin 500 mg', 'qty' => 60, 'subtotal' => 30000],
                ['code' => 'OBT-02', 'name' => 'Glimepirid 2 mg', 'qty' => 30, 'subtotal' => 45000],
            ],
        ]), $this->petugas->id);

        $this->assertCount(2, $layanan->items);
        $this->assertSame('Metformin 500 mg', $layanan->items[0]['name']);
        $this->assertSame('75000.00', $layanan->total_amount);
    }

    #[Test]
    public function riwayat_pelayanan_obat_terkumpul_per_nomor_kartu(): void
    {
        $this->prb->recordService($this->pelayanan(['served_on' => now()->subMonth()->toDateString()]), $this->petugas->id);
        $this->prb->recordService($this->pelayanan(['served_on' => now()->toDateString()]), $this->petugas->id);
        $this->prb->recordService($this->pelayanan(['card_number' => '0009999999999']), $this->petugas->id);

        $this->assertCount(2, $this->prb->serviceHistory('0001234567890'));
    }

    #[Test]
    public function rekap_pelayanan_dipisah_menurut_jenisnya(): void
    {
        $this->prb->recordService($this->pelayanan(['day_supply' => 30, 'items' => [['subtotal' => 100000]]]), $this->petugas->id);
        $this->prb->recordService($this->pelayanan([
            'service_type' => BpjsPharmacyService::KRONIS, 'day_supply' => 23,
            'items' => [['subtotal' => 50000]],
        ]), $this->petugas->id);

        $rekap = $this->prb->serviceRecap(now()->subDay()->toDateString(), now()->addDay()->toDateString())
            ->keyBy('service_type');

        $this->assertSame(1, (int) $rekap['prb']->pelayanan);
        $this->assertSame('100000.00', (string) $rekap['prb']->nilai);
        $this->assertSame(23, (int) $rekap['kronis']->rata_hari);
    }

    /** Rekap keikutsertaan memuat yang ditolak — itu justru yang dicari. */
    #[Test]
    public function rekap_keikutsertaan_memuat_status_ditolak(): void
    {
        $this->prb->reject($this->prb->offer($this->tawaran(), $this->petugas->id), 'Tidak bersedia.');
        $this->prb->offer($this->tawaran(['diagnosis_code' => self::HIPERTENSI]), $this->petugas->id);

        $rekap = $this->prb->enrollmentRecap()->keyBy('status');

        $this->assertSame(1, (int) $rekap['ditolak']->jumlah);
        $this->assertSame(1, (int) $rekap['ditawarkan']->jumlah);
    }

    // ------------------------------------------------------------------ bantu

    private function tawaran(array $ubah = []): array
    {
        return array_merge([
            'patient_id' => 1,
            'patient_mrn' => 'RM-000001',
            'patient_name' => 'Pasien PRB Uji',
            'card_number' => '0001234567890',
            'diagnosis_code' => self::DM,
            'diagnosis_display' => 'Diabetes Melitus Tipe 2',
        ], $ubah);
    }

    private function pelayanan(array $ubah = []): array
    {
        return array_merge([
            'sep_number' => 'SEP-UJI-0001',
            'card_number' => '0001234567890',
            'patient_name' => 'Pasien PRB Uji',
            'service_type' => BpjsPharmacyService::PRB,
            'day_supply' => 30,
            'served_on' => now()->toDateString(),
        ], $ubah);
    }

    /**
     * Tanggal pelayanan yang berbeda-beda untuk tiap kunjungan.
     *
     * Loket menolak pasien yang sama mendaftar dua kali di unit yang sama
     * pada hari yang sama — dan memang begitu seharusnya. Kunjungan PRB
     * yang nyata pun bulanan, bukan dua kali sehari.
     */
    private function bulanKe(): int
    {
        static $bulan = 0;

        return ++$bulan;
    }

    private function pasien(string $nama): object
    {
        return app(PatientRegistry::class)->register([
            'name' => $nama, 'sex' => 'L', 'birth_date' => '1970-05-05',
        ]);
    }

    /**
     * Satu kunjungan lengkap: registrasi BPJS, SEP terbit, dan diagnosis
     * tercatat — persis bahan yang dipakai menghitung calon PRB.
     */
    private function kunjunganBerdiagnosis(
        object $pasien,
        string $kode,
        bool $terbitkanSep = true,
        int $mundurBulan = 0,
    ): Registration {
        static $urut = 0;
        $urut++;

        $registrasi = app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', $terbitkanSep ? 'BPJS' : 'UMUM')->value('id'),
            serviceDate: now()->subMonths($mundurBulan),
        );

        if ($terbitkanSep) {
            $this->sep->create($registrasi->id, '0001234567890', 'RJ' . str_pad((string) $urut, 4, '0', STR_PAD_LEFT), $this->petugas->id);
        }

        Diagnosis::query()->create([
            'registration_id' => $registrasi->id,
            'patient_id' => $pasien->id,
            'registration_number' => $registrasi->registration_number,
            'code' => $kode,
            'display' => 'Diagnosis Uji ' . $kode,
            'rank' => Diagnosis::RANK_UTAMA,
            'certainty' => 'definitif',
            'diagnosed_at' => now(),
        ]);

        return $registrasi;
    }
}
