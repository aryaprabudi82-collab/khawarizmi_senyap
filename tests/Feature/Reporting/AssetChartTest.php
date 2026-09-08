<?php

namespace Tests\Feature\Reporting;

use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\User;
use App\Modules\Reporting\Services\ChartCatalog;
use App\Modules\Reporting\Services\ChartService;
use App\Modules\Reporting\Services\ReportingException;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Grafik aset & kesehatan lingkungan (domain O item C).
 *
 * 22 kode Khanza, nol layar baru. Yang dikunci:
 *
 * 1. PENGUKURAN LINGKUNGAN DIJUMLAHKAN, BUKAN DIHITUNG — grafik yang
 *    menghitung baris menampilkan "jumlah pencatatan" berlabel
 *    "pemakaian air".
 * 2. DATASET KEADAAN TIDAK PUNYA DERET WAKTU: berapa aset di tiap ruang
 *    adalah keadaan sekarang.
 * 3. URGENSI PENGAJUAN ADA KOLOMNYA, dan bukan cuma demi grafik.
 * 4. HITUNGAN TETAP BILANGAN BULAT, PENJUMLAHAN TETAP PECAHAN.
 */
class AssetChartTest extends TestCase
{
    use RefreshDatabase;

    private ChartService $grafik;

    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->grafik = app(ChartService::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-grafik-aset', 'name' => 'Petugas Aset',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    // ============================================== kesling dijumlahkan

    #[Test]
    public function pengukuran_lingkungan_dijumlahkan_bukan_dihitung(): void
    {
        $this->ukur('air-pdam', 120.5, 'm3');
        $this->ukur('air-pdam', 80.25, 'm3');
        $this->ukur('limbah-b3-padat', 15.0, 'kg');

        $perKategori = $this->grafik->breakdown('kesling', 'kategori', ...$this->periode());

        // Tiga baris pencatatan, tapi pemakaian air PDAM 200,75 m3 —
        // bukan 2. Grafik yang menghitung baris akan menampilkan angka
        // yang tampak masuk akal dan sepenuhnya salah.
        $this->assertSame(200.75, collect($perKategori)->firstWhere('label', 'air-pdam')['value']);
        $this->assertSame(15.0, collect($perKategori)->firstWhere('label', 'limbah-b3-padat')['value']);
        $this->assertTrue($this->grafik->isSummed('kesling'));
    }

    #[Test]
    public function deret_waktu_kesling_juga_dijumlahkan(): void
    {
        $this->ukur('air-tanah', 50.0, 'm3', 0);
        $this->ukur('air-tanah', 30.0, 'm3', 0);
        $this->ukur('air-tanah', 40.0, 'm3', 40);

        $bulanan = $this->grafik->overTime(
            'kesling', ChartCatalog::BULANAN, ...array_merge($this->periode(90), [['kategori' => 'air-tanah']])
        );

        $this->assertCount(2, $bulanan);
        $this->assertSame(120.0, array_sum(array_column($bulanan, 'value')));
    }

    #[Test]
    public function satuan_ikut_bisa_disaring_supaya_penjumlahannya_berarti(): void
    {
        $this->ukur('limbah-b3-padat', 10.0, 'kg');
        $this->ukur('limbah-b3-cair', 25.0, 'liter');

        // Menjumlahkan kilogram bersama liter menghasilkan angka yang
        // tidak berarti apa-apa; satuannya yang membuktikan penyaringnya
        // benar.
        $perSatuan = $this->grafik->breakdown('kesling', 'satuan', ...$this->periode());

        $this->assertSame(10.0, collect($perSatuan)->firstWhere('label', 'kg')['value']);
        $this->assertSame(25.0, collect($perSatuan)->firstWhere('label', 'liter')['value']);
    }

    #[Test]
    public function hitungan_tetap_bilangan_bulat(): void
    {
        $this->ajukanAset('rutin');
        $this->ajukanAset('rutin');

        $perUrgensi = $this->grafik->breakdown('pengajuan-aset', 'urgensi', ...$this->periode());

        // Angka laporan yang bentuknya berubah tanpa alasan membuat
        // pembacanya ragu apakah ada yang lain ikut berubah.
        $this->assertSame(2, collect($perUrgensi)->firstWhere('label', 'rutin')['value']);
        $this->assertFalse($this->grafik->isSummed('pengajuan-aset'));
    }

    // ============================================== dataset keadaan

    #[Test]
    public function inventaris_adalah_keadaan_bukan_peristiwa(): void
    {
        // Aset yang diperoleh lima tahun lalu TETAP ada di ruangnya
        // sekarang, dan grafik "inventaris per ruang" harus menghitungnya.
        $this->buatAset('Ruang Bedah', 'Elektromedik', 'Philips', 1800);
        $this->buatAset('Ruang Bedah', 'Elektromedik', 'GE', 0);

        $perRuang = $this->grafik->breakdown('inventaris', 'ruang', ...$this->periode(7));

        $this->assertSame(2, collect($perRuang)->firstWhere('label', 'Ruang Bedah')['value']);
    }

    #[Test]
    public function dataset_keadaan_tidak_punya_deret_waktu(): void
    {
        $this->buatAset('Ruang Bedah', 'Elektromedik', 'Philips', 100);

        $this->expectException(ReportingException::class);
        $this->expectExceptionMessageMatches('/dengan judul yang sama/');

        $this->grafik->overTime('inventaris', ChartCatalog::BULANAN, ...$this->periode());
    }

    #[Test]
    public function seluruh_sumbu_inventaris_khanza_tersedia(): void
    {
        $sumbu = $this->grafik->availableDimensions('inventaris');

        // grafik_inventaris_ruang, _jenis, _kategori, _merk, _produsen.
        foreach (['ruang', 'jenis', 'kategori', 'merk', 'produsen'] as $kunci) {
            $this->assertArrayHasKey($kunci, $sumbu);
        }
    }

    #[Test]
    public function inventaris_bisa_dikelompokkan_menurut_merk_dan_produsen(): void
    {
        $this->buatAset('Ruang A', 'Elektromedik', 'Philips', 10);
        $this->buatAset('Ruang B', 'Elektromedik', 'Philips', 10);
        $this->buatAset('Ruang B', 'Furnitur', 'Olympic', 10);

        $perMerk = $this->grafik->breakdown('inventaris', 'merk', ...$this->periode());

        $this->assertSame(2, collect($perMerk)->firstWhere('label', 'Philips')['value']);
        $this->assertSame(1, collect($perMerk)->firstWhere('label', 'Olympic')['value']);
    }

    // ============================================== urgensi pengajuan

    #[Test]
    public function pengajuan_aset_punya_urgensi(): void
    {
        $this->ajukanAset('darurat');
        $this->ajukanAset('rutin');
        $this->ajukanAset('rutin');

        $perUrgensi = $this->grafik->breakdown('pengajuan-aset', 'urgensi', ...$this->periode());

        // Tanpa kolom ini, permintaan mengganti alat rusak di ruang
        // tindakan mengantre di belakang permintaan mengganti kursi.
        $this->assertSame(1, collect($perUrgensi)->firstWhere('label', 'darurat')['value']);
        $this->assertSame(2, collect($perUrgensi)->firstWhere('label', 'rutin')['value']);
    }

    #[Test]
    public function pengajuan_lama_dianggap_rutin_bukan_kosong(): void
    {
        // Yang mendesak selalu disebutkan; yang tidak disebut biasanya
        // memang tidak mendesak. Berbeda dari jenis luka pada item B
        // yang dibiarkan NULL — di sana menebak berarti mengarang temuan
        // klinis, di sini cuma menentukan urutan antrean.
        $id = DB::table('asset.requisitions')->insertGetId([
            'requisition_number' => 'REQ-LAMA-001',
            'unit_name' => 'Poliklinik Umum',
            'status' => 'diajukan',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame('rutin', DB::table('asset.requisitions')->where('id', $id)->value('urgency'));
    }

    #[Test]
    public function basis_data_menolak_urgensi_di_luar_kosakata(): void
    {
        $this->expectException(QueryException::class);

        DB::table('asset.requisitions')->insert([
            'requisition_number' => 'REQ-NGAWUR-001',
            'unit_name' => 'Poliklinik Umum',
            'urgency' => 'sangat-sangat-darurat',
            'status' => 'diajukan',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ============================================== perbaikan

    #[Test]
    public function perbaikan_bisa_dikelompokkan_menurut_pelaksananya(): void
    {
        $aset = $this->buatAset('Ruang Bedah', 'Elektromedik', 'Philips', 10);

        $this->mintaPerbaikan($aset, $this->petugas->id);
        $this->mintaPerbaikan($aset, $this->petugas->id);
        $this->mintaPerbaikan($aset, null);

        $perPelaksana = $this->grafik->breakdown('perbaikan-inventaris', 'pelaksana', ...$this->periode());

        // Nama pelaksananya datang dari kontrak platform, bukan ID
        // mentah — grafik berisi angka 7 dan 12 tidak menjelaskan apa pun.
        $this->assertSame(2, collect($perPelaksana)->firstWhere('label', 'Petugas Aset')['value']);
        $this->assertSame(1, collect($perPelaksana)->firstWhere('label', ChartService::TIDAK_TERCATAT)['value']);
    }

    #[Test]
    public function perbaikan_punya_deret_waktu(): void
    {
        $aset = $this->buatAset('Ruang Bedah', 'Elektromedik', 'Philips', 10);

        $this->mintaPerbaikan($aset, $this->petugas->id);
        $this->mintaPerbaikan($aset, $this->petugas->id);

        $harian = $this->grafik->overTime('perbaikan-inventaris', ChartCatalog::HARIAN, ...$this->periode());

        $this->assertSame(2, array_sum(array_column($harian, 'value')));
    }

    // ---------------------------------------------------------------- fixture

    /**
     * @return array{0: string, 1: string}
     */
    private function periode(int $hari = 30): array
    {
        return [now()->subDays($hari)->toDateString(), now()->addDay()->toDateString()];
    }

    private function ukur(string $kategori, float $jumlah, string $satuan, int $mundurHari = 0): void
    {
        DB::table('asset.environmental_measurements')->insert([
            'category' => $kategori,
            'measured_on' => now()->subDays($mundurHari)->toDateString(),
            'quantity' => $jumlah,
            'unit' => $satuan,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function ajukanAset(string $urgensi): void
    {
        static $urut = 0;
        $urut++;

        DB::table('asset.requisitions')->insert([
            'requisition_number' => 'REQ-UJI-'.$urut,
            'unit_name' => 'Poliklinik Umum',
            'urgency' => $urgensi,
            'status' => 'diajukan',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function buatAset(string $ruang, string $jenis, string $merk, int $mundurHari): int
    {
        static $urut = 0;
        $urut++;

        $lokasi = DB::table('asset.locations')->where('name', $ruang)->value('id')
            ?? DB::table('asset.locations')->insertGetId([
                'code' => 'LOK'.mt_rand(1000, 9999), 'name' => $ruang, 'is_active' => true,
            ]);

        $kategori = DB::table('asset.categories')->where('name', $jenis)->value('id')
            ?? DB::table('asset.categories')->insertGetId([
                'code' => 'KAT'.mt_rand(1000, 9999), 'name' => $jenis, 'is_active' => true,
            ]);

        return DB::table('asset.assets')->insertGetId([
            'asset_number' => 'AST-UJI-'.$urut,
            'name' => 'Aset Uji '.$urut,
            'category_id' => $kategori,
            'location_id' => $lokasi,
            'brand' => $merk,
            'acquisition_date' => now()->subDays($mundurHari)->toDateString(),
            'condition' => 'baik',
            'status' => 'aktif',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function mintaPerbaikan(int $asetId, ?int $pelaksana): void
    {
        static $urut = 0;
        $urut++;

        DB::table('asset.maintenance_requests')->insert([
            'request_number' => 'MNT-UJI-'.$urut,
            'asset_id' => $asetId,
            'description' => 'Tidak menyala.',
            'status' => 'diajukan',
            'assigned_to' => $pelaksana,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
