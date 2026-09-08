<?php

namespace Tests\Feature\Reporting;

use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Reporting\Services\ChartCatalog;
use App\Modules\Reporting\Services\ChartService;
use App\Modules\Reporting\Services\ReportingException;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Grafik SDM, farmasi, gizi & layanan (domain O item D).
 *
 * Yang dikunci:
 *
 * 1. PENGELOMPOKAN OBAT MEMAKAI NAMA, BUKAN ID — grafik berisi batang
 *    berlabel 3, 7, dan 12 tidak bisa dibaca siapa pun.
 * 2. LIMA SUMBU KEPEGAWAIAN SENGAJA BELUM DIBUAT, dan dicatat sebagai
 *    keputusan lewat pendingData().
 * 3. SESI HEMODIALISA YANG DIBATALKAN DIKECUALIKAN, yang DIHENTIKAN
 *    tetap ikut.
 * 4. ORDER DIET BUKAN PORSI, dan itu dinyatakan terang-terangan.
 */
class OperationalChartTest extends TestCase
{
    use RefreshDatabase;

    private ChartService $grafik;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->grafik = app(ChartService::class);
    }

    // ============================================== farmasi

    #[Test]
    public function obat_dikelompokkan_menurut_nama_bukan_id(): void
    {
        $kategori = DB::table('pharmacy.drug_categories')->insertGetId([
            'code' => 'KAT-A', 'name' => 'Antibiotik', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->buatObat('OBT-A1', 'tablet', $kategori);
        $this->buatObat('OBT-A2', 'tablet', $kategori);
        $this->buatObat('OBT-B1', 'sirup', null);

        $perKategori = $this->grafik->breakdown('obat', 'kategori', ...$this->periode());
        $perJenis = $this->grafik->breakdown('obat', 'jenis', ...$this->periode());

        // Batang berlabel angka id tidak menjelaskan apa pun.
        $this->assertSame(2, collect($perKategori)->firstWhere('label', 'Antibiotik')['value']);
        $this->assertSame(2, collect($perJenis)->firstWhere('label', 'tablet')['value']);
    }

    #[Test]
    public function obat_tanpa_kategori_tampil_sebagai_tidak_tercatat(): void
    {
        $this->buatObat('OBT-C1', 'kapsul', null);

        $perKategori = $this->grafik->breakdown('obat', 'kategori', ...$this->periode());

        $this->assertSame(ChartService::TIDAK_TERCATAT, $perKategori[0]['label']);
    }

    #[Test]
    public function katalog_obat_adalah_keadaan_bukan_peristiwa(): void
    {
        $this->buatObat('OBT-D1', 'tablet', null);

        // Obat yang terdaftar tahun lalu tetap ada di formularium hari
        // ini; menyaringnya dengan periode menjawab pertanyaan lain.
        $this->expectException(ReportingException::class);
        $this->expectExceptionMessageMatches('/dengan judul yang sama/');

        $this->grafik->overTime('obat', ChartCatalog::BULANAN, ...$this->periode());
    }

    // ============================================== kepegawaian

    #[Test]
    public function pegawai_bisa_dikelompokkan_menurut_pendidikan(): void
    {
        $this->buatPegawai('Perawat', 'D3 Keperawatan', 'tetap');
        $this->buatPegawai('Perawat', 'S1 Keperawatan', 'tetap');
        $this->buatPegawai('Dokter', 'Profesi Dokter', 'kontrak');

        $perPendidikan = $this->grafik->breakdown('pegawai', 'pendidikan', ...$this->periode());
        $perStatus = $this->grafik->breakdown('pegawai', 'status-kerja', ...$this->periode());

        $this->assertSame(1, collect($perPendidikan)->firstWhere('label', 'S1 Keperawatan')['value']);
        $this->assertSame(2, collect($perStatus)->firstWhere('label', 'tetap')['value']);
    }

    #[Test]
    public function lima_sumbu_kepegawaian_sengaja_belum_dibuat(): void
    {
        $sumbu = $this->grafik->availableDimensions('pegawai');

        // Menebak kosakatanya menghasilkan grafik resmi berisi kategori
        // yang tidak pernah disepakati siapa pun — kekeliruan yang sama
        // seperti mengarang isi formulir klinis pada domain M.
        foreach (['jenjang-jabatan', 'kelompok-jabatan', 'status-wp', 'risiko-kerja', 'emergency-index'] as $belum) {
            $this->assertArrayNotHasKey($belum, $sumbu);
        }

        $tertunda = ChartCatalog::pendingData();

        $this->assertArrayHasKey('grafik_jenjang_jabatanpegawai', $tertunda);
        $this->assertArrayHasKey('grafik_emergency_indexpegawai', $tertunda);
        $this->assertCount(7, $tertunda);
    }

    #[Test]
    public function alasan_setiap_sumbu_tertunda_disebutkan(): void
    {
        // Daftar yang cuma menyebut kodenya tanpa alasan akan dibaca
        // sebagai daftar pekerjaan yang belum sempat, bukan keputusan.
        foreach (ChartCatalog::pendingData() as $kode => $alasan) {
            $this->assertNotEmpty($alasan, "Kode {$kode} tertunda tanpa alasan.");
            $this->assertGreaterThan(20, strlen($alasan));
        }
    }

    // ============================================== hemodialisa

    #[Test]
    public function sesi_hemodialisa_yang_dibatalkan_tidak_ikut_dihitung(): void
    {
        $this->buatSesiDialisis('selesai', 'av-fistula');
        $this->buatSesiDialisis('dihentikan', 'av-fistula');
        $this->buatSesiDialisis('dibatalkan', 'av-fistula');

        $total = $this->grafik->total('hemodialisa', ...$this->periode());

        // Sesi batal bukan sesi; tapi yang dihentikan di tengah TETAP
        // ikut, karena pasiennya benar-benar didialisis.
        $this->assertSame(2, $total);
    }

    #[Test]
    public function sesi_hemodialisa_punya_deret_waktu(): void
    {
        $this->buatSesiDialisis('selesai', 'av-fistula', 0);
        $this->buatSesiDialisis('selesai', 'av-graft', 0);
        $this->buatSesiDialisis('selesai', 'av-fistula', 40);

        $bulanan = $this->grafik->overTime('hemodialisa', ChartCatalog::BULANAN, ...$this->periode(90));
        $perAkses = $this->grafik->breakdown('hemodialisa', 'akses', ...$this->periode(90));

        $this->assertCount(2, $bulanan);
        $this->assertSame(2, collect($perAkses)->firstWhere('label', 'av-fistula')['value']);
    }

    #[Test]
    public function keadaan_klinis_dialisis_tidak_ikut_diterbitkan(): void
    {
        $kolom = DB::select(
            "SELECT column_name FROM information_schema.columns
              WHERE table_schema='clinical' AND table_name='v_dialysis_session'"
        );
        $nama = array_column($kolom, 'column_name');

        // Grafik menanyakan berapa banyak sesi berjalan, bukan keadaan
        // klinis tiap pasien.
        foreach (['dry_weight_kg', 'target_ultrafiltration_l', 'complications', 'anticoagulant'] as $klinis) {
            $this->assertNotContains($klinis, $nama);
        }
    }

    // ============================================== diet & kepulangan

    #[Test]
    public function order_diet_bisa_dikelompokkan_menurut_bangsal(): void
    {
        $sumbu = $this->grafik->availableDimensions('diet');

        // Penggabungan admisi-bed-ruang dilakukan di kontrak supaya
        // tidak ditemukan ulang tiap kali ada yang menghitung per bangsal.
        $this->assertArrayHasKey('bangsal', $sumbu);
        $this->assertArrayHasKey('jenis-diet', $sumbu);
        $this->assertArrayHasKey('kelas', $sumbu);
    }

    #[Test]
    public function kepulangan_ranap_bisa_dikelompokkan_menurut_status_pulang(): void
    {
        $sumbu = $this->grafik->availableDimensions('ranap-pulang');

        // grafik_sttspulangranap dan grafik_bulanan_meninggal: satu
        // dataset, dua cara membacanya.
        $this->assertArrayHasKey('status-pulang', $sumbu);
        $this->assertArrayHasKey('bangsal', $sumbu);
    }

    #[Test]
    public function seluruh_dataset_menyebut_kode_khanza_yang_dinaunginya(): void
    {
        // Dataset tanpa keterangan kode yang dinaunginya membuat
        // pertanyaan "kode Khanza mana yang sudah selesai" harus dijawab
        // dengan menebak.
        foreach (ChartCatalog::datasets() as $kunci => $isi) {
            $this->assertArrayHasKey('khanza', $isi, "Dataset '{$kunci}' tidak menyebut kode Khanza-nya.");
            $this->assertNotEmpty($isi['khanza']);
        }
    }

    // ---------------------------------------------------------------- fixture

    /**
     * @return array{0: string, 1: string}
     */
    private function periode(int $hari = 30): array
    {
        return [now()->subDays($hari)->toDateString(), now()->addDay()->toDateString()];
    }

    private function buatObat(string $kode, string $bentuk, ?int $kategoriId): void
    {
        DB::table('pharmacy.drugs')->insert([
            'code' => $kode, 'name' => 'Obat '.$kode,
            'category' => 'obat', 'form' => $bentuk, 'unit' => 'tablet',
            'drug_category_id' => $kategoriId,
            'requires_prescription' => true, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function buatPegawai(string $jabatan, string $pendidikan, string $statusKerja): void
    {
        static $urut = 0;
        $urut++;

        DB::table('hr.employees')->insert([
            'employee_number' => 'PEG-UJI-'.$urut,
            'name' => 'Pegawai Uji '.$urut,
            'position' => $jabatan,
            'education' => $pendidikan,
            'employment_type' => $statusKerja,
            'hire_date' => now()->subYear()->toDateString(),
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function buatSesiDialisis(string $status, string $akses, int $mundurHari = 0): void
    {
        static $urut = 0;
        $urut++;

        DB::table('clinical.dialysis_sessions')->insert([
            'registration_id' => 900000 + $urut,
            'patient_id' => 800000 + $urut,
            'registration_number' => 'REG-HD-'.$urut,
            'patient_mrn' => 'RM-HD-'.$urut,
            'patient_name' => 'Pasien HD '.$urut,
            'started_at' => now()->subDays($mundurHari),
            'ended_at' => now()->subDays($mundurHari)->addHours(4),
            'access_type' => $akses,
            'status' => $status,
            'termination_reason' => $status === 'dihentikan' ? 'Hipotensi' : null,
            'recorded_by_name' => 'Ns. Uji',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
