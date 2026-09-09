<?php

namespace Tests\Feature\Retail;

use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use App\Modules\Retail\Models\PriceTier;
use App\Modules\Retail\Models\Product;
use App\Modules\Retail\Models\StockMovement;
use App\Modules\Retail\Services\ProductService;
use App\Modules\Retail\Services\RetailException;
use App\Modules\Retail\Services\RetailStockLedger;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Produk, harga & stok toko (domain S item A).
 *
 * Yang dikunci:
 *
 * 1. STOK DIHITUNG DARI BUKU BESAR, tidak disimpan sebagai kolom pada
 *    barangnya seperti `tokobarang.stok` Khanza.
 * 2. ARAH PERGERAKAN DITETAPKAN JENISNYA, tidak diterima dari pemanggil.
 * 3. TINGKAT HARGA JADI BARIS, bukan tiga kolom tetap.
 * 4. SELISIH OPNAME DIHITUNG, dan opname tidak bisa ditutup selama ada
 *    barang yang belum dihitung fisik.
 */
class ProductStockTest extends TestCase
{
    use RefreshDatabase;

    private ProductService $produk;

    private RetailStockLedger $ledger;

    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->produk = app(ProductService::class);
        $this->ledger = app(RetailStockLedger::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-petugas-toko', 'name' => 'Petugas Toko',
            'password' => 'password', 'is_active' => true,
        ]);
        $this->petugas->roles()->attach(Role::query()->where('code', 'petugas-toko')->firstOrFail());
    }

    // ============================================== stok dari buku besar

    #[Test]
    public function barang_tidak_punya_kolom_stok(): void
    {
        /*
         * `tokobarang.stok` Khanza adalah saldo berjalan yang menempel pada
         * barangnya. Saldo tanpa buku besar tidak bisa direkonsiliasi:
         * begitu satu transaksi gagal di tengah, angkanya melenceng dan
         * tidak ada cara menelusuri sejak kapan maupun karena apa.
         */
        $kolom = DB::select(
            "SELECT column_name FROM information_schema.columns
              WHERE table_schema='retail' AND table_name='products'"
        );
        $nama = array_column($kolom, 'column_name');

        $this->assertNotContains('stok', $nama);
        $this->assertNotContains('stock', $nama);
        $this->assertContains('minimum_stock', $nama);
    }

    #[Test]
    public function stok_dihitung_dari_penjumlahan_pergerakan(): void
    {
        $barang = $this->barang('BRG-001', 'Air Mineral 600ml');

        $this->ledger->record($barang, StockMovement::MASUK, 100, 3000, 'TRM-001');
        $this->ledger->record($barang, StockMovement::KELUAR, 30, null, 'JUAL-001');
        $this->ledger->record($barang, StockMovement::RETUR_MASUK, 5, null, 'RTJ-001');

        $this->assertSame(75, $this->ledger->stock($barang));
        $this->assertSame(75, $barang->stok());
    }

    #[Test]
    public function arah_pergerakan_ditetapkan_jenisnya_bukan_tandanya(): void
    {
        $barang = $this->barang('BRG-002', 'Roti');
        $this->ledger->record($barang, StockMovement::MASUK, 50);

        // Pemanggil mengirim jumlah POSITIF untuk penjualan; arahnya tetap
        // negatif karena jenisnya yang menentukan.
        $this->ledger->record($barang, StockMovement::KELUAR, 20);

        $this->assertSame(30, $this->ledger->stock($barang));
        $this->assertSame(-20, StockMovement::query()->where('kind', 'keluar')->value('quantity'));
    }

    #[Test]
    public function jumlah_pergerakan_harus_lebih_dari_nol(): void
    {
        $barang = $this->barang('BRG-003', 'Teh Kotak');

        // Pemanggil yang boleh mengirim tanda bisa menambah stok lewat
        // penjualan — aturan yang sama seperti arah kas pada finance.
        $this->expectException(RetailException::class);
        $this->expectExceptionMessageMatches('/arahnya ditentukan jenisnya, bukan tandanya/');

        $this->ledger->record($barang, StockMovement::KELUAR, -5);
    }

    #[Test]
    public function stok_tidak_boleh_jadi_minus(): void
    {
        $barang = $this->barang('BRG-004', 'Sabun');
        $this->ledger->record($barang, StockMovement::MASUK, 10);

        /*
         * Stok minus berarti barang terjual lebih banyak daripada yang
         * pernah masuk — yang tersembunyi di situ bukan kesalahan hitung,
         * melainkan penerimaan yang tidak dicatat.
         */
        $this->expectException(RetailException::class);
        $this->expectExceptionMessageMatches('/penerimaan yang tidak dicatat/');

        $this->ledger->record($barang, StockMovement::KELUAR, 15);
    }

    #[Test]
    public function koreksi_tidak_bisa_dibuat_lewat_record_biasa(): void
    {
        $barang = $this->barang('BRG-005', 'Kopi');

        // Koreksi satu-satunya pergerakan yang tandanya bebas, jadi ia hanya
        // boleh lahir dari penyelesaian stok opname — kalau bisa dibuat
        // bebas, ia jadi pintu paling mudah untuk menutupi kehilangan.
        $this->expectException(RetailException::class);
        $this->expectExceptionMessageMatches('/hanya boleh lahir dari penyelesaian stok opname/');

        $this->ledger->record($barang, StockMovement::KOREKSI, 5);
    }

    #[Test]
    public function basis_data_menolak_pergerakan_bernilai_nol(): void
    {
        $barang = $this->barang('BRG-006', 'Gula');

        $this->expectException(QueryException::class);

        DB::table('retail.stock_movements')->insert([
            'product_id' => $barang->id, 'kind' => 'masuk', 'quantity' => 0,
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // =============================================== tingkat harga

    #[Test]
    public function tingkat_harga_bisa_lebih_dari_tiga(): void
    {
        $barang = $this->barang('BRG-007', 'Masker');

        foreach (['distributor', 'grosir', 'retail', 'karyawan'] as $urut => $kode) {
            $tingkat = PriceTier::query()->create([
                'code' => $kode, 'name' => ucfirst($kode), 'position' => $urut + 1, 'is_active' => true,
            ]);
            $this->produk->setPrice($barang, $tingkat, 1000 * ($urut + 1));
        }

        /*
         * Khanza menetapkan tepat tiga tingkat sebagai kolom. Koperasi rumah
         * sakit hampir selalu punya tingkat keempat — harga karyawan — dan
         * kolom tetap tidak bisa menyatakannya tanpa migrasi; lebih buruk
         * lagi, kolom keempat yang ditambahkan belakangan akan kosong pada
         * seluruh barang lama dan terbaca sebagai "gratis".
         */
        $this->assertCount(4, $barang->load('prices')->prices);
    }

    #[Test]
    public function harga_jual_negatif_ditolak(): void
    {
        $barang = $this->barang('BRG-008', 'Tisu');
        $tingkat = $this->tingkat('retail');

        $this->expectException(RetailException::class);
        $this->expectExceptionMessageMatches('/bukan diskon, ia salah ketik/');

        $this->produk->setPrice($barang, $tingkat, -500);
    }

    #[Test]
    public function patokan_marjin_berversi_dan_hanya_mengusulkan(): void
    {
        $barang = $this->barang('BRG-009', 'Susu', 10000);
        $tingkat = $this->tingkat('retail');

        $this->produk->setPricingPolicy($tingkat, 20);
        $this->assertEqualsWithDelta(12000.0, $this->produk->suggestedPrice($barang, $tingkat), 0.01);

        // Harga yang SUDAH ditetapkan tidak ikut berubah saat patokan diubah:
        // menerapkannya otomatis akan mengubah harga seluruh barang, termasuk
        // yang harganya memang sengaja ditetapkan di luar patokan.
        $this->produk->setPrice($barang, $tingkat, 15000);
        $this->produk->setPricingPolicy($tingkat, 50);

        $this->assertEqualsWithDelta(
            15000.0,
            (float) $barang->load('prices')->prices->first()->price,
            0.01
        );

        // Yang lama dinonaktifkan, bukan ditimpa — `tokosetharga` Khanza satu
        // baris tanpa kunci, jadi "patokan mana yang berlaku waktu itu" tidak
        // punya jawaban.
        $this->assertSame(2, DB::table('retail.pricing_policies')->count());
        $this->assertSame(1, DB::table('retail.pricing_policies')->where('is_active', true)->count());
    }

    // ==================================================== opname

    #[Test]
    public function opname_membekukan_stok_sistem_saat_sesi_dibuka(): void
    {
        $barang = $this->barang('BRG-010', 'Biskuit');
        $this->ledger->record($barang, StockMovement::MASUK, 100);

        $opname = $this->produk->openOpname([], $this->petugas->id, 'Petugas Toko');

        // Penjualan terjadi SELAMA penghitungan fisik berlangsung.
        $this->ledger->record($barang, StockMovement::KELUAR, 10);

        /*
         * Kalau stok sistem dibaca ulang saat menutup, penjualan itu akan
         * tampak sebagai selisih hitung, dan petugas akan mengejar
         * kehilangan yang tidak pernah ada.
         */
        $baris = $opname->items->firstWhere('product_id', $barang->id);
        $this->assertSame(100, $baris->system_quantity);
    }

    #[Test]
    public function selisih_dan_nilainya_dihitung_bukan_disimpan(): void
    {
        $kolom = DB::select(
            "SELECT column_name FROM information_schema.columns
              WHERE table_schema='retail' AND table_name='stock_opname_items'"
        );
        $nama = array_column($kolom, 'column_name');

        // `tokoopname.selisih` dan `nomihilang` Khanza adalah nilai turunan
        // yang dibekukan — dan nilai turunan yang dibekukan akan salah
        // begitu hitungan fisiknya dikoreksi, tanpa ada yang tahu kapan.
        $this->assertNotContains('selisih', $nama);
        $this->assertNotContains('difference', $nama);
        $this->assertContains('system_quantity', $nama);
        $this->assertContains('counted_quantity', $nama);
    }

    #[Test]
    public function opname_tidak_bisa_ditutup_selama_ada_barang_belum_dihitung(): void
    {
        $this->barang('BRG-011', 'Permen');
        $this->barang('BRG-012', 'Cokelat');

        $opname = $this->produk->openOpname([], $this->petugas->id);

        $this->produk->recordCount($opname->items->first(), 0);

        /*
         * Menutup opname dengan baris kosong berarti stok barang itu
         * dikoreksi ke angka yang tidak pernah dihitung siapa pun — dan
         * sesudahnya buku besar akan tampak sudah dicocokkan.
         */
        $this->expectException(RetailException::class);
        $this->expectExceptionMessageMatches('/tidak pernah dihitung siapa pun/');

        $this->produk->completeOpname($opname->load('items'));
    }

    #[Test]
    public function selisih_opname_masuk_buku_besar_sebagai_koreksi(): void
    {
        $barang = $this->barang('BRG-013', 'Pulpen', 5000);
        $this->ledger->record($barang, StockMovement::MASUK, 50);

        $opname = $this->produk->openOpname([], $this->petugas->id);
        $baris = $opname->items->firstWhere('product_id', $barang->id);

        // Fisik 47, sistem 50 — kurang 3.
        $this->produk->recordCount($baris, 47, 'Tiga hilang di rak');

        $this->produk->completeOpname($opname->load('items'), $this->petugas->id);

        /*
         * Selisih masuk buku besar sebagai koreksi, TIDAK menimpa saldo:
         * selisih yang ditimpakan menghapus sebabnya bersama angkanya.
         */
        $koreksi = StockMovement::query()->where('kind', StockMovement::KOREKSI)->first();

        $this->assertSame(-3, $koreksi->quantity);
        $this->assertSame($opname->opname_number, $koreksi->reference);
        $this->assertSame(47, $this->ledger->stock($barang));
    }

    #[Test]
    public function selisih_nol_tidak_menghasilkan_baris_koreksi(): void
    {
        $barang = $this->barang('BRG-014', 'Buku Tulis');
        $this->ledger->record($barang, StockMovement::MASUK, 20);

        $opname = $this->produk->openOpname([], $this->petugas->id);
        $this->produk->recordCount($opname->items->first(), 20);
        $this->produk->completeOpname($opname->load('items'), $this->petugas->id);

        // Riwayat yang berisi pergerakan tanpa akibat membuat penelusuran
        // selisih jauh lebih lama.
        $this->assertSame(0, StockMovement::query()->where('kind', StockMovement::KOREKSI)->count());
    }

    #[Test]
    public function nilai_selisih_dihitung_dari_harga_pokok_yang_dibekukan(): void
    {
        $barang = $this->barang('BRG-015', 'Sikat Gigi', 8000);
        $this->ledger->record($barang, StockMovement::MASUK, 30);

        $opname = $this->produk->openOpname([], $this->petugas->id);
        $baris = $opname->items->firstWhere('product_id', $barang->id);
        $this->produk->recordCount($baris, 28);

        $this->assertSame(-2, $baris->refresh()->selisih());
        $this->assertEqualsWithDelta(-16000.0, $baris->nilaiSelisih(), 0.01);
    }

    #[Test]
    public function hitungan_fisik_nol_adalah_jawaban_yang_sah(): void
    {
        $barang = $this->barang('BRG-016', 'Korek');
        $this->ledger->record($barang, StockMovement::MASUK, 5);

        $opname = $this->produk->openOpname([], $this->petugas->id);
        $baris = $opname->items->firstWhere('product_id', $barang->id);

        // Nol berarti habis, dan itu justru temuan yang penting — bukan
        // "belum dihitung".
        $this->produk->recordCount($baris, 0, 'Rak kosong');

        $this->assertSame(0, $baris->refresh()->counted_quantity);
        $this->assertSame(-5, $baris->selisih());
    }

    #[Test]
    public function opname_yang_sudah_ditutup_tidak_bisa_ditutup_ulang(): void
    {
        $this->barang('BRG-017', 'Sedotan');
        $opname = $this->produk->openOpname([], $this->petugas->id);
        $this->produk->recordCount($opname->items->first(), 0);
        $this->produk->completeOpname($opname->load('items'), $this->petugas->id);

        $this->expectException(RetailException::class);
        $this->expectExceptionMessageMatches('/sudah selesai atau dibatalkan/');

        $this->produk->completeOpname($opname->refresh()->load('items'));
    }

    // ================================================== kewenangan

    #[Test]
    public function layar_toko_untuk_petugas_toko_bukan_petugas_logistik(): void
    {
        $this->actingAs($this->petugas)->get(route('retail.index'))->assertOk();
        $this->actingAs($this->petugas)->get(route('retail.stok.index'))->assertOk();

        /*
         * Stok toko dan stok medis memang harus terpisah, dan menggabungkan
         * perannya membuka jalan yang persis ingin dihindari — permintaan
         * bangsal menarik barang dagangan koperasi, atau obat terjual di
         * kasir toko.
         */
        $logistik = User::query()->create([
            'username' => 'uji-logistik-toko', 'name' => 'Petugas Logistik',
            'password' => 'password', 'is_active' => true,
        ]);
        $logistik->roles()->attach(Role::query()->where('code', 'petugas-logistik')->firstOrFail());

        $this->actingAs($logistik)->get(route('retail.index'))->assertForbidden();
    }

    // -------------------------------------------------------- fixture

    private function barang(string $kode, string $nama, float $pokok = 1000): Product
    {
        return Product::query()->create([
            'code' => $kode, 'name' => $nama, 'unit' => 'pcs',
            'base_cost' => $pokok, 'minimum_stock' => 0, 'is_active' => true,
        ]);
    }

    private function tingkat(string $kode): PriceTier
    {
        return PriceTier::query()->firstOrCreate(
            ['code' => $kode],
            ['name' => ucfirst($kode), 'position' => 1, 'is_active' => true]
        );
    }
}
