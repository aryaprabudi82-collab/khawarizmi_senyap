<?php

namespace Tests\Feature\Retail;

use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use App\Modules\Retail\Models\Member;
use App\Modules\Retail\Models\PriceTier;
use App\Modules\Retail\Models\Product;
use App\Modules\Retail\Models\Sale;
use App\Modules\Retail\Models\StockMovement;
use App\Modules\Retail\Services\ProductService;
use App\Modules\Retail\Services\RetailException;
use App\Modules\Retail\Services\RetailStockLedger;
use App\Modules\Retail\Services\SalesService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Penjualan, piutang & rekap toko (domain S item C).
 *
 * Yang dikunci:
 *
 * 1. TUNAI DAN PIUTANG SATU TABEL — laporan tidak bisa lupa separuhnya.
 * 2. HARGA POKOK DIBEKUKAN per baris; keuntungan bulan lalu tidak
 *    berubah saat ada penerimaan baru.
 * 3. SISA PIUTANG DIHITUNG, tidak disimpan sebagai saldo.
 * 4. RETUR PIUTANG MENGURANGI TAGIHAN, bukan mengeluarkan kas.
 */
class SalesTest extends TestCase
{
    use RefreshDatabase;

    private SalesService $jual;

    private ProductService $produk;

    private RetailStockLedger $ledger;

    private User $petugas;

    private PriceTier $tingkat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->jual = app(SalesService::class);
        $this->produk = app(ProductService::class);
        $this->ledger = app(RetailStockLedger::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-kasir-toko', 'name' => 'Kasir Toko',
            'password' => 'password', 'is_active' => true,
        ]);
        $this->petugas->roles()->attach(Role::query()->where('code', 'petugas-toko')->firstOrFail());

        $this->tingkat = PriceTier::query()->create([
            'code' => 'retail', 'name' => 'Retail', 'position' => 1, 'is_active' => true,
        ]);
    }

    // ================================ satu tabel untuk dua cara bayar

    #[Test]
    public function penjualan_tunai_dan_piutang_tersimpan_di_tabel_yang_sama(): void
    {
        $barang = $this->barangBerstok('BRG-J1', 100, 2000, 3000);

        $this->jual->sell(['payment_type' => 'tunai'], [['product_id' => $barang->id, 'quantity' => 2]], null, $this->tingkat);
        $this->jual->sell(
            ['payment_type' => 'piutang', 'due_on' => now()->addDays(30)->toDateString()],
            [['product_id' => $barang->id, 'quantity' => 3]],
            null,
            $this->tingkat
        );

        /*
         * Khanza memisahkannya jadi dua tabel; laporan penjualan yang lupa
         * salah satunya menjawab dengan tenang dengan angka yang lebih kecil
         * daripada kenyataannya.
         */
        $this->assertSame(2, Sale::query()->count());
        $this->assertEqualsWithDelta(15000.0, (float) Sale::query()->sum('total'), 0.01);
    }

    #[Test]
    public function penjualan_mengurangi_stok_lewat_buku_besar(): void
    {
        $barang = $this->barangBerstok('BRG-J2', 50, 1000, 1500);

        $this->jual->sell([], [['product_id' => $barang->id, 'quantity' => 10]], null, $this->tingkat);

        $this->assertSame(40, $this->ledger->stock($barang));
        $this->assertSame(1, StockMovement::query()->where('kind', StockMovement::KELUAR)->count());
    }

    #[Test]
    public function barang_tanpa_harga_pada_tingkat_itu_ditolak(): void
    {
        $barang = Product::query()->create([
            'code' => 'BRG-J3', 'name' => 'Tanpa Harga', 'unit' => 'pcs',
            'base_cost' => 1000, 'minimum_stock' => 0, 'is_active' => true,
        ]);
        $this->ledger->record($barang, StockMovement::MASUK, 10, 1000);

        // Menjual tanpa harga yang ditetapkan berarti kasir yang
        // menentukannya di tempat.
        $this->expectException(RetailException::class);
        $this->expectExceptionMessageMatches('/kasir yang menentukannya di tempat/');

        $this->jual->sell([], [['product_id' => $barang->id, 'quantity' => 1]], null, $this->tingkat);
    }

    #[Test]
    public function basis_data_menolak_penjualan_tunai_berjatuh_tempo(): void
    {
        $barang = $this->barangBerstok('BRG-J4', 10, 1000, 2000);
        $penjualan = $this->jual->sell([], [['product_id' => $barang->id, 'quantity' => 1]], null, $this->tingkat);

        // Jatuh tempo pada penjualan tunai membuat laporan piutang memuat
        // nota yang sudah dibayar.
        $this->expectException(QueryException::class);

        DB::table('retail.sales')->where('id', $penjualan->id)
            ->update(['due_on' => now()->addDays(7)->toDateString()]);
    }

    // ============================================ harga pokok beku

    #[Test]
    public function keuntungan_tidak_berubah_saat_harga_pokok_barang_naik(): void
    {
        $barang = $this->barangBerstok('BRG-J5', 100, 2000, 5000);

        $penjualan = $this->jual->sell([], [['product_id' => $barang->id, 'quantity' => 10]], null, $this->tingkat);
        $untungAwal = $penjualan->items->first()->untung();

        // Penerimaan baru dengan harga pokok jauh lebih tinggi.
        $barang->update(['base_cost' => 4500]);

        /*
         * Kalau harga pokok dibaca dari barangnya saat laporan dibuat,
         * keuntungan bulan lalu akan berubah setiap kali ada penerimaan baru
         * — dan laporan keuangan yang angkanya berubah sendiri tidak bisa
         * dipakai menutup buku.
         */
        $this->assertEqualsWithDelta(30000.0, $untungAwal, 0.01);
        $this->assertEqualsWithDelta(30000.0, $penjualan->refresh()->load('items')->items->first()->untung(), 0.01);
    }

    // ==================================================== piutang

    #[Test]
    public function sisa_piutang_dihitung_dari_pembayaran(): void
    {
        $barang = $this->barangBerstok('BRG-J6', 100, 1000, 2000);

        $penjualan = $this->jual->sell(
            ['payment_type' => 'piutang', 'due_on' => now()->addDays(30)->toDateString()],
            [['product_id' => $barang->id, 'quantity' => 10]],
            null,
            $this->tingkat
        );

        $this->assertEqualsWithDelta(20000.0, $penjualan->sisaPiutang(), 0.01);
        $this->assertSame(Sale::BELUM, $penjualan->payment_status);

        $this->jual->recordPayment($penjualan, 8000);
        $this->assertEqualsWithDelta(12000.0, $penjualan->refresh()->sisaPiutang(), 0.01);
        $this->assertSame(Sale::SEBAGIAN, $penjualan->payment_status);

        $this->jual->recordPayment($penjualan->refresh(), 12000);
        $this->assertSame(Sale::LUNAS, $penjualan->refresh()->payment_status);
    }

    #[Test]
    public function uang_muka_langsung_tercatat_sebagai_pembayaran(): void
    {
        $barang = $this->barangBerstok('BRG-J7', 100, 1000, 2000);

        $penjualan = $this->jual->sell(
            ['payment_type' => 'piutang', 'down_payment' => 5000, 'due_on' => now()->addDays(30)->toDateString()],
            [['product_id' => $barang->id, 'quantity' => 10]],
            null,
            $this->tingkat
        );

        $this->assertSame(1, $penjualan->refresh()->payments()->count());
        $this->assertEqualsWithDelta(15000.0, $penjualan->sisaPiutang(), 0.01);
        $this->assertSame(Sale::SEBAGIAN, $penjualan->payment_status);
    }

    #[Test]
    public function pembayaran_melebihi_sisa_piutang_ditolak(): void
    {
        $barang = $this->barangBerstok('BRG-J8', 100, 1000, 2000);
        $penjualan = $this->jual->sell(
            ['payment_type' => 'piutang', 'due_on' => now()->addDays(30)->toDateString()],
            [['product_id' => $barang->id, 'quantity' => 5]],
            null,
            $this->tingkat
        );

        $this->expectException(RetailException::class);
        $this->expectExceptionMessageMatches('/melebihi sisa piutang/');

        $this->jual->recordPayment($penjualan, 999999);
    }

    #[Test]
    public function penjualan_tunai_tidak_punya_piutang_untuk_dibayar(): void
    {
        $barang = $this->barangBerstok('BRG-J9', 100, 1000, 2000);
        $penjualan = $this->jual->sell([], [['product_id' => $barang->id, 'quantity' => 1]], null, $this->tingkat);

        $this->expectException(RetailException::class);
        $this->expectExceptionMessageMatches('/tidak punya piutang/');

        $this->jual->recordPayment($penjualan, 100);
    }

    // ====================================================== retur

    #[Test]
    public function retur_mengembalikan_stok_dan_tidak_boleh_melebihi_yang_dijual(): void
    {
        $barang = $this->barangBerstok('BRG-J10', 100, 1000, 2000);
        $penjualan = $this->jual->sell([], [['product_id' => $barang->id, 'quantity' => 10]], null, $this->tingkat);

        $this->jual->returnSale($penjualan, ['reason' => 'Kemasan penyok'], [['product_id' => $barang->id, 'quantity' => 4]]);

        $this->assertSame(94, $this->ledger->stock($barang));

        /*
         * Retur lebih banyak daripada yang pernah dibeli berarti barang dari
         * tempat lain masuk ke stok sambil uangnya keluar dari kas.
         */
        $this->expectException(RetailException::class);
        $this->expectExceptionMessageMatches('/barang dari tempat lain masuk/');

        $this->jual->returnSale($penjualan->refresh(), ['reason' => 'Lagi'], [['product_id' => $barang->id, 'quantity' => 7]]);
    }

    #[Test]
    public function retur_atas_piutang_mengurangi_tagihan_bukan_kas(): void
    {
        $barang = $this->barangBerstok('BRG-J11', 100, 1000, 2000);

        $penjualan = $this->jual->sell(
            ['payment_type' => 'piutang', 'due_on' => now()->addDays(30)->toDateString()],
            [['product_id' => $barang->id, 'quantity' => 10]],
            null,
            $this->tingkat
        );

        $retur = $this->jual->returnSale($penjualan, ['reason' => 'Rusak'], [['product_id' => $barang->id, 'quantity' => 3]]);

        /*
         * Menyamakannya dengan retur tunai membuat kas tercatat keluar untuk
         * uang yang belum pernah masuk.
         */
        $this->assertFalse($retur->refunded_in_cash);
        $this->assertEqualsWithDelta(14000.0, $penjualan->refresh()->sisaPiutang(), 0.01);
    }

    #[Test]
    public function retur_tunai_ditandai_mengembalikan_kas(): void
    {
        $barang = $this->barangBerstok('BRG-J12', 100, 1000, 2000);
        $penjualan = $this->jual->sell([], [['product_id' => $barang->id, 'quantity' => 5]], null, $this->tingkat);

        $retur = $this->jual->returnSale($penjualan, ['reason' => 'Salah beli'], [['product_id' => $barang->id, 'quantity' => 2]]);

        $this->assertTrue($retur->refunded_in_cash);
    }

    #[Test]
    public function retur_wajib_beralasan(): void
    {
        $barang = $this->barangBerstok('BRG-J13', 100, 1000, 2000);
        $penjualan = $this->jual->sell([], [['product_id' => $barang->id, 'quantity' => 1]], null, $this->tingkat);

        $this->expectException(RetailException::class);
        $this->expectExceptionMessageMatches('/wajib beralasan/');

        $this->jual->returnSale($penjualan, ['reason' => '  '], [['product_id' => $barang->id, 'quantity' => 1]]);
    }

    // ====================================================== rekap

    #[Test]
    public function rekap_harian_memuat_tunai_dan_cicilan_piutang(): void
    {
        $barang = $this->barangBerstok('BRG-J14', 200, 1000, 2000);
        $hari = now()->toDateString();

        $this->jual->sell([], [['product_id' => $barang->id, 'quantity' => 10]], null, $this->tingkat);

        $kredit = $this->jual->sell(
            ['payment_type' => 'piutang', 'due_on' => now()->addDays(30)->toDateString()],
            [['product_id' => $barang->id, 'quantity' => 10]],
            null,
            $this->tingkat
        );
        $this->jual->recordPayment($kredit, 5000);

        $rekap = $this->jual->dailyRecap($hari, $hari);

        /*
         * Empat kode rekap Khanza dilayani satu hitungan: keempatnya membaca
         * data yang sama dari sudut berbeda, dan empat layar berarti empat
         * tempat yang bisa berbeda jawabannya untuk hari yang sama.
         */
        $this->assertSame(2, $rekap['jumlah_nota']);
        $this->assertEqualsWithDelta(40000.0, $rekap['nilai_penjualan'], 0.01);
        // Kas masuk: tunai 20.000 + cicilan 5.000.
        $this->assertEqualsWithDelta(25000.0, $rekap['pendapatan_kas'], 0.01);
        $this->assertEqualsWithDelta(20000.0, $rekap['modal_terjual'], 0.01);
        $this->assertEqualsWithDelta(20000.0, $rekap['keuntungan'], 0.01);
    }

    #[Test]
    public function keuntungan_per_barang_memakai_harga_pokok_beku(): void
    {
        $satu = $this->barangBerstok('BRG-J15', 50, 1000, 2500);
        $dua = $this->barangBerstok('BRG-J16', 50, 3000, 4000);
        $hari = now()->toDateString();

        $this->jual->sell([], [
            ['product_id' => $satu->id, 'quantity' => 10],
            ['product_id' => $dua->id, 'quantity' => 10],
        ], null, $this->tingkat);

        $untung = $this->jual->profitByProduct($hari, $hari);

        $this->assertCount(2, $untung);
        // Barang pertama untung 1.500/unit, kedua 1.000/unit — jadi yang
        // pertama di atas.
        $this->assertSame('BRG-J15', $untung->first()->code);
        $this->assertEqualsWithDelta(15000.0, (float) $untung->first()->untung, 0.01);
    }

    #[Test]
    public function daftar_piutang_memuat_yang_belum_lunas_saja(): void
    {
        $barang = $this->barangBerstok('BRG-J17', 100, 1000, 2000);

        $lunas = $this->jual->sell(
            ['payment_type' => 'piutang', 'due_on' => now()->addDays(10)->toDateString()],
            [['product_id' => $barang->id, 'quantity' => 5]],
            null,
            $this->tingkat
        );
        $this->jual->recordPayment($lunas, 10000);

        $belum = $this->jual->sell(
            ['payment_type' => 'piutang', 'due_on' => now()->addDays(5)->toDateString()],
            [['product_id' => $barang->id, 'quantity' => 5]],
            null,
            $this->tingkat
        );

        $this->jual->sell([], [['product_id' => $barang->id, 'quantity' => 5]], null, $this->tingkat);

        $daftar = $this->jual->outstandingReceivables();

        $this->assertCount(1, $daftar);
        $this->assertSame($belum->id, $daftar->first()->id);
    }

    #[Test]
    public function member_membawa_tingkat_harganya_sendiri(): void
    {
        $karyawan = PriceTier::query()->create([
            'code' => 'karyawan', 'name' => 'Karyawan', 'position' => 2, 'is_active' => true,
        ]);

        $barang = $this->barangBerstok('BRG-J18', 100, 1000, 2000);
        $this->produk->setPrice($barang, $karyawan, 1200);

        $member = Member::query()->create([
            'member_number' => 'MBR-001', 'name' => 'Budi Karyawan',
            'default_price_tier_id' => $karyawan->id,
            'joined_on' => now()->toDateString(), 'is_active' => true,
        ]);

        $penjualan = $this->jual->sell([], [['product_id' => $barang->id, 'quantity' => 5]], $member);

        // Tingkat harga keempat — yang tiga kolom tetap Khanza tidak bisa
        // menyatakannya — dipakai sungguhan di sini.
        $this->assertEqualsWithDelta(6000.0, (float) $penjualan->total, 0.01);
        $this->assertSame($karyawan->id, $penjualan->price_tier_id);
    }

    #[Test]
    public function layar_kasir_terbuka_untuk_petugas_toko(): void
    {
        $this->actingAs($this->petugas)->get(route('retail.penjualan.index'))->assertOk();

        $kasir = User::query()->create([
            'username' => 'uji-kasir-rs', 'name' => 'Kasir RS',
            'password' => 'password', 'is_active' => true,
        ]);
        $kasir->roles()->attach(Role::query()->where('code', 'kasir')->firstOrFail());

        // Kasir rumah sakit menagih layanan pasien; kasir toko menjual
        // barang dagangan. Menyatukan perannya membuat penerimaan koperasi
        // bercampur dengan penerimaan pelayanan.
        $this->actingAs($kasir)->get(route('retail.penjualan.index'))->assertForbidden();
    }

    // -------------------------------------------------------- fixture

    private function barangBerstok(string $kode, int $stok, float $pokok, float $jual): Product
    {
        $barang = Product::query()->create([
            'code' => $kode, 'name' => 'Barang '.$kode, 'unit' => 'pcs',
            'base_cost' => $pokok, 'minimum_stock' => 0, 'is_active' => true,
        ]);

        $this->produk->setPrice($barang, $this->tingkat, $jual);
        $this->ledger->record($barang, StockMovement::MASUK, $stok, $pokok);

        return $barang->refresh();
    }
}
