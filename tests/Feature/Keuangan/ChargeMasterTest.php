<?php

namespace Tests\Feature\Keuangan;

use App\Modules\Keuangan\MasterData\Application\ChargeMasterService;
use App\Modules\Keuangan\MasterData\Domain\ChargeItem;
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
 * Charge Description Master — Modul A, Wave 1.
 *
 * ATURAN KRITIS YANG DIKUNCI DI SINI: item tanpa pemetaan akun TIDAK BISA
 * diaktifkan. Item yang menagih tanpa akun berarti ada uang masuk yang
 * tidak pernah sampai ke buku besar — dan selisihnya baru ketahuan
 * berbulan-bulan kemudian saat ada yang menutup buku.
 *
 * Ditegakkan DUA LAPIS, dan keduanya diuji: CHECK di basis data (yang
 * menjamin), dan pemeriksaan di service (yang menjelaskan). Lapis kedua
 * ada karena galat SQL mentah tidak menolong siapa pun.
 */
class ChargeMasterTest extends TestCase
{
    use RefreshDatabase;

    private ChargeMasterService $cdm;

    private int $akunPendapatan;

    private int $akunBeban;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->cdm = app(ChargeMasterService::class);

        $this->akunPendapatan = $this->akun('4-8001', 'Pendapatan Uji', 'pendapatan');
        $this->akunBeban = $this->akun('5-8001', 'Beban Pokok Uji', 'beban');
    }

    // -------------------------------------------------------- pendaftaran

    /**
     * Item lahir NONAKTIF. Mendaftar dan mengizinkan menagih adalah dua
     * keputusan berbeda, sering oleh orang yang berbeda.
     */
    #[Test]
    public function item_baru_lahir_nonaktif(): void
    {
        $item = $this->daftar('TND-001');

        $this->assertFalse($item->is_active);
        $this->assertFalse($item->siapDiaktifkan());
    }

    #[Test]
    public function kode_ditulis_huruf_besar_dan_tidak_boleh_kembar(): void
    {
        $this->assertSame('TND-002', $this->daftar('tnd-002')->code);

        $this->expectException(KeuanganException::class);
        $this->expectExceptionMessage('sudah dipakai');

        $this->daftar('TND-002');
    }

    /**
     * Dua item CDM yang menunjuk baris tarif yang sama akan menagih hal
     * yang sama dua kali dengan kode berbeda, dan rekonsiliasinya
     * mustahil.
     */
    #[Test]
    public function satu_sumber_hanya_boleh_punya_satu_item_berjalan(): void
    {
        $this->daftar('TND-003', sourceId: 77);

        $this->expectException(KeuanganException::class);
        $this->expectExceptionMessage('sudah tertaut');

        $this->daftar('TND-004', sourceId: 77);
    }

    /** Setelah item lama di-expire, sumbernya boleh ditaut item baru. */
    #[Test]
    public function sumber_boleh_ditaut_ulang_setelah_item_lama_expire(): void
    {
        $lama = $this->daftar('TND-005', sourceId: 88);
        $this->cdm->expire($lama, now()->toDateString());

        $baru = $this->daftar('TND-006', sourceId: 88);

        $this->assertSame('TND-006', $baru->code);
    }

    // ------------------------------------------------------- pengaktifan

    /** INTI MODUL A. */
    #[Test]
    public function item_tanpa_akun_pendapatan_tidak_bisa_diaktifkan(): void
    {
        $item = $this->daftar('TND-007');

        $this->expectException(KeuanganException::class);
        $this->expectExceptionMessage('Belum dipetakan ke akun pendapatan');

        $this->cdm->aktifkan($item);
    }

    /**
     * Barang berpersediaan menuntut akun beban pokok juga. Tanpa itu,
     * HPP-nya tidak bisa dijurnalkan dan nilai persediaan di buku besar
     * akan terus melenceng dari gudang.
     */
    #[Test]
    public function obat_tanpa_akun_beban_pokok_tidak_bisa_diaktifkan(): void
    {
        $obat = $this->daftar('OBT-001', golongan: ChargeItem::GOL_OBAT, sourceContext: 'pharmacy');
        $this->cdm->petakanAkun($obat, $this->akunPendapatan);

        $this->expectException(KeuanganException::class);
        $this->expectExceptionMessage('akun beban pokok');

        $this->cdm->aktifkan($obat);
    }

    #[Test]
    public function item_yang_sudah_dipetakan_bisa_diaktifkan(): void
    {
        $item = $this->daftar('TND-008');
        $this->cdm->petakanAkun($item, $this->akunPendapatan);

        $this->assertTrue($this->cdm->aktifkan($item)->is_active);
    }

    #[Test]
    public function obat_dengan_kedua_akun_bisa_diaktifkan(): void
    {
        $obat = $this->daftar('OBT-002', golongan: ChargeItem::GOL_OBAT, sourceContext: 'pharmacy');
        $this->cdm->petakanAkun($obat, $this->akunPendapatan, $this->akunBeban);

        $this->assertTrue($this->cdm->aktifkan($obat)->is_active);
    }

    /**
     * LAPIS KEDUA: basis data menolaknya juga, dibuktikan dengan menulis
     * langsung lewat query builder — melewati seluruh pemeriksaan PHP.
     * Tanpa lapis ini, satu seeder atau satu perbaikan data lewat tinker
     * cukup untuk melahirkan item yang menagih tanpa akun.
     */
    #[Test]
    public function basis_data_juga_menolak_item_aktif_tanpa_akun(): void
    {
        $this->expectException(QueryException::class);

        DB::table('keuangan_master.charge_items')->insert([
            'code' => 'BYPASS-001',
            'name' => 'Lewat service',
            'golongan' => ChargeItem::GOL_TINDAKAN,
            'source_context' => 'catalog',
            'is_active' => true,
            'revenue_account_id' => null,
            'valid_from' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function akun_yang_tidak_ada_ditolak(): void
    {
        $this->expectException(KeuanganException::class);
        $this->expectExceptionMessage('tidak ditemukan');

        $this->cdm->petakanAkun($this->daftar('TND-009'), 999999);
    }

    // ------------------------------------------------------------ expire

    /**
     * Item di-EXPIRE, bukan dihapus. Tagihan lama tetap menunjuknya dan
     * tetap harus bisa dibaca.
     */
    #[Test]
    public function item_di_expire_bukan_dihapus(): void
    {
        $item = $this->daftar('TND-010');
        $this->cdm->petakanAkun($item, $this->akunPendapatan);
        $this->cdm->aktifkan($item);

        $this->cdm->expire($item->refresh(), now()->toDateString());

        $this->assertDatabaseHas('keuangan_master.charge_items', ['code' => 'TND-010']);
        $this->assertNotNull($item->refresh()->valid_until);

        /*
         * `is_active` SENGAJA TIDAK diubah oleh expire. Keduanya menjawab
         * pertanyaan berbeda: `is_active` = boleh dipakai menagih;
         * `valid_until` = sampai kapan ia pernah berlaku. Menyetel
         * is_active=false saat expire membuat item HILANG dari seluruh
         * tanggal, termasuk masa ia masih sah — dan rekap tarif bulan lalu
         * kehilangan item yang waktu itu benar-benar dipakai menagih.
         */
        $this->assertTrue($item->refresh()->is_active,
            'expire() tidak boleh mengubah is_active — yang menentukan berlaku atau tidak '
            .'pada satu tanggal adalah rentang tanggalnya');
    }

    #[Test]
    public function tanggal_berakhir_tidak_boleh_mendahului_tanggal_mulai(): void
    {
        $item = $this->daftar('TND-011');

        $this->expectException(KeuanganException::class);
        $this->expectExceptionMessage('mendahului');

        $this->cdm->expire($item, now()->subYear()->toDateString());
    }

    // ------------------------------------------------ cakupan & pemetaan

    #[Test]
    public function daftar_belum_dipetakan_menunjukkan_pekerjaan_yang_tersisa(): void
    {
        $this->daftar('TND-012');

        $sudah = $this->daftar('TND-013');
        $this->cdm->petakanAkun($sudah, $this->akunPendapatan);

        $belum = $this->cdm->belumDipetakan()->pluck('code')->all();

        $this->assertContains('TND-012', $belum);
        $this->assertNotContains('TND-013', $belum);
    }

    #[Test]
    public function cakupan_pemetaan_dihitung_benar(): void
    {
        $a = $this->daftar('TND-014');
        $this->cdm->petakanAkun($a, $this->akunPendapatan);
        $this->daftar('TND-015');

        $cakupan = $this->cdm->cakupanPemetaan();

        $this->assertSame(2, $cakupan['total']);
        $this->assertSame(1, $cakupan['terpetakan']);
        $this->assertSame(50.0, $cakupan['persen']);
    }

    /**
     * Katalog kosong berarti cakupannya TIDAK ADA, bukan 100%.
     * Melaporkan katalog kosong sebagai "cakupan penuh" adalah jenis
     * kabar baik yang menghentikan pertanyaan berikutnya.
     */
    #[Test]
    public function katalog_kosong_bukan_cakupan_seratus_persen(): void
    {
        $this->assertNull($this->cdm->cakupanPemetaan()['persen']);
    }

    /**
     * Item yang sudah di-expire tidak lagi muncul pada tanggal setelahnya.
     *
     * Itemnya sengaja dibuat berlaku sejak bulan lalu lalu ditutup
     * kemarin — meniru keadaan nyata: tarif yang ternyata sudah tidak
     * berlaku sejak awal bulan, ditutup pada tanggal itu, bukan pada
     * tanggal seseorang kebetulan menyadarinya.
     */
    #[Test]
    public function item_expired_tidak_muncul_pada_tanggal_setelahnya(): void
    {
        $item = $this->daftar('TND-016', validFrom: now()->subMonth()->toDateString());
        $this->cdm->petakanAkun($item, $this->akunPendapatan);
        $this->cdm->aktifkan($item);

        $this->cdm->expire($item->refresh(), now()->subDay()->toDateString());

        $this->assertCount(0, $this->cdm->berlakuPada(now()->toDateString()));
        $this->assertCount(1, $this->cdm->berlakuPada(now()->subWeek()->toDateString()),
            'Pada tanggal ketika ia masih berlaku, item itu harus tetap ditemukan');
    }

    // ---------------------------------------------------------- pembantu

    private function daftar(
        string $kode,
        string $golongan = ChargeItem::GOL_TINDAKAN,
        string $sourceContext = 'catalog',
        ?int $sourceId = null,
        ?string $validFrom = null,
    ): ChargeItem {
        return $this->cdm->daftarkan([
            'code' => $kode,
            'name' => 'Item '.$kode,
            'golongan' => $golongan,
            'source_context' => $sourceContext,
            'source_id' => $sourceId,
            'valid_from' => $validFrom ?? now()->toDateString(),
        ]);
    }

    private function akun(string $kode, string $nama, string $jenis): int
    {
        return DB::table('finance.chart_of_accounts')->insertGetId([
            'code' => $kode,
            'name' => $nama,
            'type' => $jenis,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
