<?php

namespace Tests\Feature\Keuangan;

use App\Modules\Keuangan\MasterData\Application\CostCenterService;
use App\Modules\Keuangan\Shared\Domain\KeuanganException;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pusat biaya & pusat pendapatan — Modul A butir 1.10.
 *
 * DUA ATURAN YANG PALING MENENTUKAN, dan keduanya soal ARAH ALOKASI:
 *
 * 1. Pusat PENDAPATAN tidak boleh punya cost driver — ia penerima
 *    alokasi, bukan pemberi. Memberinya driver menyiratkan biayanya
 *    dialokasikan lagi ke tempat lain, dan alokasi berputar tidak pernah
 *    selesai dihitung.
 *
 * 2. Pusat yang DIALOKASIKAN wajib punya driver. Tanpa itu, biayanya
 *    tertinggal di tempatnya sendiri dan laporan unit cost menunjukkan
 *    layanan yang jauh lebih murah daripada kenyataannya — karena biaya
 *    laundry dan gizi tidak pernah sampai ke sana.
 */
class CostCenterTest extends TestCase
{
    use RefreshDatabase;

    private CostCenterService $pusat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->pusat = app(CostCenterService::class);
    }

    // ------------------------------------------------------------- arah alokasi

    #[Test]
    public function pusat_pendapatan_tidak_boleh_punya_cost_driver(): void
    {
        $this->expectException(KeuanganException::class);
        $this->expectExceptionMessage('PENERIMA alokasi');

        $this->daftar('RC-1', CostCenterService::REVENUE, driver: 'luas-lantai');
    }

    #[Test]
    public function pusat_yang_dialokasikan_wajib_punya_driver(): void
    {
        $this->expectException(KeuanganException::class);
        $this->expectExceptionMessage('wajib punya cost driver');

        $this->daftar('CC-1', CostCenterService::COST, driver: null);
    }

    /** Basis data menolaknya juga, melewati seluruh pemeriksaan PHP. */
    #[Test]
    public function basis_data_menolak_revenue_center_berdriver(): void
    {
        $this->expectException(QueryException::class);

        DB::table('keuangan_master.cost_centers')->insert([
            'code' => 'BYPASS-RC',
            'name' => 'Lewat service',
            'jenis' => CostCenterService::REVENUE,
            'cost_driver' => 'luas-lantai',
            'is_active' => true,
            'valid_from' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function pendaftaran_yang_benar_diterima(): void
    {
        $rc = $this->daftar('RC-2', CostCenterService::REVENUE);
        $cc = $this->daftar('CC-2', CostCenterService::COST, driver: 'berat-cucian');

        $this->assertSame(CostCenterService::REVENUE, $rc->jenis);
        $this->assertNull($rc->cost_driver);
        $this->assertSame('berat-cucian', $cc->cost_driver);
    }

    // ------------------------------------------------------- program PTN-BH

    /**
     * Program-center sengaja dipisah dari support-center. Menggabungkannya
     * membuat biaya pendidikan tersebar ke tarif pelayanan lewat alokasi
     * biasa — dan itu berarti pasien ikut membiayai pendidikan tanpa ada
     * yang memutuskannya.
     */
    #[Test]
    public function program_center_pendidikan_terpisah_dari_support(): void
    {
        $this->daftar('PC-1', CostCenterService::PROGRAM, driver: 'jumlah-pegawai', program: 'pendidikan');
        $this->daftar('SC-1', CostCenterService::SUPPORT, driver: 'jumlah-pegawai');

        $program = $this->pusat->berlakuPada(now()->toDateString(), CostCenterService::PROGRAM);

        $this->assertCount(1, $program);
        $this->assertSame('pendidikan', $program->first()->default_program);
    }

    #[Test]
    public function yang_dialokasikan_memuat_tiga_jenis_bukan_revenue(): void
    {
        $this->daftar('RC-3', CostCenterService::REVENUE);
        $this->daftar('CC-3', CostCenterService::COST, driver: 'berat-cucian');
        $this->daftar('SC-3', CostCenterService::SUPPORT, driver: 'jumlah-pegawai');
        $this->daftar('PC-3', CostCenterService::PROGRAM, driver: 'jumlah-pegawai');

        $dialokasikan = $this->pusat->yangDialokasikan(now()->toDateString())->pluck('code')->all();

        $this->assertCount(3, $dialokasikan);
        $this->assertNotContains('RC-3', $dialokasikan,
            'Pusat pendapatan adalah penerima alokasi, bukan pemberi');
    }

    // ------------------------------------------------------ perubahan klasifikasi

    /**
     * Klasifikasi diubah dengan MENG-EXPIRE yang lama, bukan menimpanya.
     * Laporan tahun lalu harus tetap memakai klasifikasi yang berlaku
     * waktu itu — menimpanya membuat biaya yang dulu dialokasikan tampak
     * seolah tidak pernah dialokasikan.
     */
    #[Test]
    public function perubahan_klasifikasi_meng_expire_bukan_menimpa(): void
    {
        $this->daftar('POLI-JTG', CostCenterService::COST,
            driver: 'jumlah-kunjungan', validFrom: '2025-01-01');

        $this->pusat->ubahKlasifikasi('POLI-JTG', CostCenterService::REVENUE, '2026-01-01');

        $lama = DB::table('keuangan_master.cost_centers')->where('code', 'POLI-JTG')->first();
        $this->assertSame('2025-12-31', $lama->valid_until);
        $this->assertSame(CostCenterService::COST, $lama->jenis);

        // Pada 2025 ia masih pusat biaya; pada 2026 sudah pusat pendapatan.
        $this->assertSame(CostCenterService::COST,
            $this->pusat->berlakuPada('2025-06-01')->firstWhere('code', 'POLI-JTG')->jenis);

        $baru = $this->pusat->berlakuPada('2026-06-01')->first();
        $this->assertSame(CostCenterService::REVENUE, $baru->jenis);
    }

    #[Test]
    public function klasifikasi_baru_tidak_boleh_mendahului_yang_berjalan(): void
    {
        $this->daftar('POLI-X', CostCenterService::COST, driver: 'jumlah-kunjungan', validFrom: '2026-01-01');

        $this->expectException(KeuanganException::class);
        $this->expectExceptionMessage('tidak boleh mendahului');

        $this->pusat->ubahKlasifikasi('POLI-X', CostCenterService::REVENUE, '2025-06-01');
    }

    // --------------------------------------------------------- kelengkapan

    /**
     * Unit tanpa pusat biaya berarti biayanya tidak masuk perhitungan unit
     * cost mana pun — dan laporan margin per layanan jadi terlalu bagus
     * tanpa ada yang terlihat salah.
     */
    #[Test]
    public function unit_yang_belum_terklasifikasi_dilaporkan(): void
    {
        $unit = (int) DB::table('organization.units')->where('is_active', true)->value('id');

        $sebelum = $this->pusat->unitBelumTerklasifikasi()->count();
        $this->assertGreaterThan(0, $sebelum, 'Seluruh unit awalnya belum terklasifikasi');

        $this->daftar('RC-U', CostCenterService::REVENUE, unitId: $unit);

        $this->assertSame($sebelum - 1, $this->pusat->unitBelumTerklasifikasi()->count());
    }

    #[Test]
    public function unit_yang_tidak_ada_ditolak(): void
    {
        $this->expectException(KeuanganException::class);
        $this->expectExceptionMessage('tidak ditemukan');

        $this->daftar('RC-Z', CostCenterService::REVENUE, unitId: 999999);
    }

    #[Test]
    public function kode_tidak_boleh_kembar(): void
    {
        $this->daftar('RC-4', CostCenterService::REVENUE);

        $this->expectException(KeuanganException::class);
        $this->expectExceptionMessage('sudah dipakai');

        $this->daftar('RC-4', CostCenterService::REVENUE);
    }

    // ------------------------------------------------------------- pembantu

    private function daftar(
        string $kode,
        string $jenis,
        ?string $driver = null,
        ?string $program = null,
        ?int $unitId = null,
        ?string $validFrom = null,
    ): object {
        return $this->pusat->daftarkan([
            'code' => $kode,
            'name' => 'Pusat '.$kode,
            'jenis' => $jenis,
            'cost_driver' => $driver,
            'default_program' => $program,
            'unit_id' => $unitId,
            'valid_from' => $validFrom ?? now()->subYear()->toDateString(),
        ]);
    }
}
