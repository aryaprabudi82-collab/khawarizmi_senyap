<?php

namespace Tests\Feature\Retail;

use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use App\Modules\Retail\Models\GoodsReceipt;
use App\Modules\Retail\Models\Product;
use App\Modules\Retail\Models\PurchaseOrder;
use App\Modules\Retail\Models\Requisition;
use App\Modules\Retail\Models\StockMovement;
use App\Modules\Retail\Models\Supplier;
use App\Modules\Retail\Services\ProcurementService;
use App\Modules\Retail\Services\RetailException;
use App\Modules\Retail\Services\RetailStockLedger;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Rantai pengadaan toko (domain S item B).
 *
 * Yang dikunci:
 *
 * 1. PENERIMAAN TIDAK BOLEH MELEBIHI SISA PESANAN.
 * 2. HUTANG DIHITUNG dari selisih nilai penerimaan dan yang sudah
 *    dibayar, bukan disimpan sebagai saldo.
 * 3. HARGA POKOK BARANG mengikuti penerimaan terakhir; HPP penjualan
 *    dibekukan terpisah.
 * 4. SURAT PEMESANAN bukan entitas kedua — ia tampilan cetak pesanan.
 * 5. PENOLAKAN PENGAJUAN WAJIB BERALASAN, persetujuan tidak.
 */
class ProcurementTest extends TestCase
{
    use RefreshDatabase;

    private ProcurementService $pengadaan;

    private RetailStockLedger $ledger;

    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->pengadaan = app(ProcurementService::class);
        $this->ledger = app(RetailStockLedger::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-toko-pengadaan', 'name' => 'Petugas Toko',
            'password' => 'password', 'is_active' => true,
        ]);
        $this->petugas->roles()->attach(Role::query()->where('code', 'petugas-toko')->firstOrFail());
    }

    // ==================================================== pengajuan

    #[Test]
    public function penolakan_pengajuan_wajib_beralasan(): void
    {
        $pengajuan = $this->ajukan();

        /*
         * Pengajuan yang DISETUJUI berbukti pada pesanan yang lahir
         * sesudahnya; yang DITOLAK tidak meninggalkan apa pun selain
         * catatan ini.
         */
        $this->expectException(RetailException::class);
        $this->expectExceptionMessageMatches('/menyebutkan alasannya/');

        $this->pengadaan->decideRequisition($pengajuan, false, null, 'Kepala Toko');
    }

    #[Test]
    public function persetujuan_pengajuan_tidak_wajib_berketerangan(): void
    {
        $hasil = $this->pengadaan->decideRequisition($this->ajukan(), true, null, 'Kepala Toko');

        $this->assertSame(Requisition::STATUS_DISETUJUI, $hasil->status);
    }

    #[Test]
    public function basis_data_menolak_penolakan_tanpa_alasan(): void
    {
        $pengajuan = $this->ajukan();

        $this->expectException(QueryException::class);

        DB::table('retail.requisitions')->where('id', $pengajuan->id)
            ->update(['status' => 'ditolak', 'decision_note' => null]);
    }

    #[Test]
    public function pengajuan_yang_belum_disetujui_tidak_bisa_jadi_dasar_pesanan(): void
    {
        $pengajuan = $this->ajukan();

        // Kalau bisa, persetujuannya cuma formalitas yang dilewati saat
        // sedang buru-buru.
        $this->expectException(RetailException::class);
        $this->expectExceptionMessageMatches('/cuma formalitas/');

        $this->pengadaan->order(
            $this->suplier(),
            [],
            [['product_id' => $this->barang('BRG-P1')->id, 'quantity' => 5, 'unit_cost' => 1000]],
            $pengajuan
        );
    }

    // ================================================== penerimaan

    #[Test]
    public function penerimaan_menambah_stok_lewat_buku_besar(): void
    {
        $barang = $this->barang('BRG-P2');
        $pesanan = $this->pesan($barang, 100, 5000);

        $this->pengadaan->receive($pesanan, [], [['product_id' => $barang->id, 'quantity' => 100]], $this->petugas->id);

        $this->assertSame(100, $this->ledger->stock($barang));
        $this->assertSame(1, StockMovement::query()->where('kind', StockMovement::MASUK)->count());
    }

    #[Test]
    public function penerimaan_melebihi_sisa_pesanan_ditolak(): void
    {
        $barang = $this->barang('BRG-P3');
        $pesanan = $this->pesan($barang, 50, 2000);

        /*
         * Barang yang datang lebih banyak daripada yang dipesan bukan
         * kelebihan yang menyenangkan: ia berarti pesanannya salah dicatat,
         * atau ada kiriman yang tidak pernah dipesan siapa pun.
         */
        $this->expectException(RetailException::class);
        $this->expectExceptionMessageMatches('/berhenti di meja penerimaan/');

        $this->pengadaan->receive($pesanan, [], [['product_id' => $barang->id, 'quantity' => 60]]);
    }

    #[Test]
    public function penerimaan_bertahap_menandai_pesanan_sebagian_lalu_diterima(): void
    {
        $barang = $this->barang('BRG-P4');
        $pesanan = $this->pesan($barang, 30, 1000);

        $this->pengadaan->receive($pesanan, [], [['product_id' => $barang->id, 'quantity' => 10]]);
        $this->assertSame(PurchaseOrder::STATUS_SEBAGIAN, $pesanan->refresh()->status);

        $this->pengadaan->receive($pesanan->refresh(), [], [['product_id' => $barang->id, 'quantity' => 20]]);
        $this->assertSame(PurchaseOrder::STATUS_DITERIMA, $pesanan->refresh()->status);

        $this->assertSame(30, $this->ledger->stock($barang));
    }

    #[Test]
    public function sisa_pesanan_dihitung_bukan_disimpan(): void
    {
        $kolom = DB::select(
            "SELECT column_name FROM information_schema.columns
              WHERE table_schema='retail' AND table_name='purchase_order_items'"
        );
        $nama = array_column($kolom, 'column_name');

        // Kolom "sisa" atau "diterima" yang disimpan akan melenceng begitu
        // satu penerimaan gagal di tengah.
        $this->assertNotContains('received_quantity', $nama);
        $this->assertNotContains('remaining_quantity', $nama);
    }

    #[Test]
    public function harga_pokok_barang_mengikuti_penerimaan_terakhir(): void
    {
        $barang = $this->barang('BRG-P5', 1000);

        $satu = $this->pesan($barang, 10, 4000);
        $this->pengadaan->receive($satu, [], [['product_id' => $barang->id, 'quantity' => 10]]);
        $this->assertEqualsWithDelta(4000.0, (float) $barang->refresh()->base_cost, 0.01);

        $dua = $this->pesan($barang, 10, 4500);
        $this->pengadaan->receive($dua, [], [['product_id' => $barang->id, 'quantity' => 10]]);

        /*
         * Harga pokok barang dipakai mengusulkan harga jual berikutnya; HPP
         * yang dipakai menghitung keuntungan dibekukan per baris penjualan.
         * Menyatukan keduanya membuat keuntungan bulan lalu berubah setiap
         * kali ada penerimaan baru.
         */
        $this->assertEqualsWithDelta(4500.0, (float) $barang->refresh()->base_cost, 0.01);
    }

    // ============================================ hutang & pembayaran

    #[Test]
    public function hutang_dihitung_dari_selisih_nilai_dan_pembayaran(): void
    {
        $barang = $this->barang('BRG-P6');
        $pesanan = $this->pesan($barang, 10, 5000);
        $terima = $this->pengadaan->receive($pesanan, [], [['product_id' => $barang->id, 'quantity' => 10]]);

        $this->assertEqualsWithDelta(50000.0, (float) $terima->total_amount, 0.01);
        $this->assertEqualsWithDelta(50000.0, $terima->sisaHutang(), 0.01);

        $this->pengadaan->payReceipt($terima, 20000);
        $this->assertEqualsWithDelta(30000.0, $terima->refresh()->sisaHutang(), 0.01);
        $this->assertSame(GoodsReceipt::BAYAR_SEBAGIAN, $terima->payment_status);

        $this->pengadaan->payReceipt($terima->refresh(), 30000);
        $this->assertEqualsWithDelta(0.0, $terima->refresh()->sisaHutang(), 0.01);
        $this->assertSame(GoodsReceipt::BAYAR_LUNAS, $terima->payment_status);
    }

    #[Test]
    public function pembayaran_melebihi_sisa_hutang_ditolak(): void
    {
        $barang = $this->barang('BRG-P7');
        $pesanan = $this->pesan($barang, 5, 1000);
        $terima = $this->pengadaan->receive($pesanan, [], [['product_id' => $barang->id, 'quantity' => 5]]);

        /*
         * Kelebihan bayar yang dibiarkan berarti nota lain ikut terbayar di
         * sini tanpa tercatat, dan hutang atas nota itu akan tampak masih
         * terbuka.
         */
        $this->expectException(RetailException::class);
        $this->expectExceptionMessageMatches('/tampak masih terbuka/');

        $this->pengadaan->payReceipt($terima, 10000);
    }

    #[Test]
    public function basis_data_menolak_terbayar_melebihi_nilai_nota(): void
    {
        $barang = $this->barang('BRG-P8');
        $pesanan = $this->pesan($barang, 5, 1000);
        $terima = $this->pengadaan->receive($pesanan, [], [['product_id' => $barang->id, 'quantity' => 5]]);

        $this->expectException(QueryException::class);

        DB::table('retail.goods_receipts')->where('id', $terima->id)->update(['paid_amount' => 999999]);
    }

    #[Test]
    public function daftar_hutang_memuat_yang_belum_lunas_saja(): void
    {
        $barang = $this->barang('BRG-P9');

        $lunas = $this->pengadaan->receive($this->pesan($barang, 5, 1000), [], [['product_id' => $barang->id, 'quantity' => 5]]);
        $this->pengadaan->payReceipt($lunas, 5000);

        $belum = $this->pengadaan->receive($this->pesan($barang, 3, 1000), [], [['product_id' => $barang->id, 'quantity' => 3]]);

        $daftar = $this->pengadaan->outstandingPayables();

        $this->assertCount(1, $daftar);
        $this->assertSame($belum->id, $daftar->first()->id);
    }

    // ======================================================== retur

    #[Test]
    public function retur_ke_suplier_wajib_beralasan(): void
    {
        $barang = $this->barang('BRG-P10');

        /*
         * Tanpa alasan, retur tidak bisa dibedakan dari barang yang hilang
         * lalu dicatat sebagai retur.
         */
        $this->expectException(RetailException::class);
        $this->expectExceptionMessageMatches('/hilang lalu dicatat sebagai retur/');

        $this->pengadaan->returnToSupplier(
            $this->suplier(),
            ['reason' => '   '],
            [['product_id' => $barang->id, 'quantity' => 1]]
        );
    }

    #[Test]
    public function retur_mengurangi_stok_lewat_buku_besar(): void
    {
        $barang = $this->barang('BRG-P11');
        $pesanan = $this->pesan($barang, 20, 2000);
        $this->pengadaan->receive($pesanan, [], [['product_id' => $barang->id, 'quantity' => 20]]);

        $this->pengadaan->returnToSupplier(
            $this->suplier(),
            ['reason' => 'Kemasan rusak saat diterima'],
            [['product_id' => $barang->id, 'quantity' => 5]]
        );

        $this->assertSame(15, $this->ledger->stock($barang));
        $this->assertSame(1, StockMovement::query()->where('kind', StockMovement::RETUR_KELUAR)->count());
    }

    #[Test]
    public function retur_melebihi_stok_ditolak_oleh_penjaga_buku_besar(): void
    {
        $barang = $this->barang('BRG-P12');
        $pesanan = $this->pesan($barang, 5, 1000);
        $this->pengadaan->receive($pesanan, [], [['product_id' => $barang->id, 'quantity' => 5]]);

        $this->expectException(RetailException::class);
        $this->expectExceptionMessageMatches('/penerimaan yang tidak dicatat/');

        $this->pengadaan->returnToSupplier(
            $this->suplier(),
            ['reason' => 'Rusak'],
            [['product_id' => $barang->id, 'quantity' => 10]]
        );
    }

    // ==================================================== layar

    #[Test]
    public function layar_pengadaan_terbuka_untuk_petugas_toko(): void
    {
        $this->actingAs($this->petugas)->get(route('retail.pengadaan.index'))->assertOk();

        $barang = $this->barang('BRG-P13');
        $pesanan = $this->pesan($barang, 5, 1000);

        // Surat pemesanan adalah tampilan cetak pesanan yang sama, bukan
        // entitas kedua — jadi ia dijamin selalu sepakat dengan pesanannya.
        $this->actingAs($this->petugas)
            ->get(route('retail.pengadaan.pesanan.surat', $pesanan))
            ->assertOk()
            ->assertSee($pesanan->order_number)
            ->assertSee($barang->name);
    }

    // -------------------------------------------------------- fixture

    private function barang(string $kode, float $pokok = 1000): Product
    {
        return Product::query()->create([
            'code' => $kode, 'name' => 'Barang '.$kode, 'unit' => 'pcs',
            'base_cost' => $pokok, 'minimum_stock' => 0, 'is_active' => true,
        ]);
    }

    private function suplier(): Supplier
    {
        return Supplier::query()->firstOrCreate(
            ['code' => 'SUP-01'],
            ['name' => 'Suplier Uji', 'is_active' => true]
        );
    }

    private function ajukan(): Requisition
    {
        return $this->pengadaan->requisition(
            ['requested_by_name' => 'Petugas Toko', 'purpose' => 'Stok menipis'],
            [['product_id' => $this->barang('BRG-AJU-'.uniqid())->id, 'quantity' => 10]],
            $this->petugas->id
        );
    }

    private function pesan(Product $barang, int $jumlah, float $harga): PurchaseOrder
    {
        $pesanan = $this->pengadaan->order(
            $this->suplier(),
            [],
            [['product_id' => $barang->id, 'quantity' => $jumlah, 'unit_cost' => $harga]],
            null,
            $this->petugas->id
        );

        return $this->pengadaan->sendOrder($pesanan);
    }
}
