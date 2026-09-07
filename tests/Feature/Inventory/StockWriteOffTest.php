<?php

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockWriteOff;
use App\Modules\Inventory\Services\InventoryException;
use App\Modules\Inventory\Services\ItemService;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Inventory\Services\StockWriteOffService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Penghapusan stok rusak & kedaluwarsa (domain N item C).
 *
 * Menaungi utd_medis_rusak dan utd_penunjang_rusak, tapi dibangun di
 * INVENTORY karena membuang barang habis pakai adalah urusan gudang dan
 * UTD cuma satu unit di antara banyak yang melakukannya.
 *
 * Yang dikunci:
 *
 * 1. STOK BARU BERKURANG SETELAH DISETUJUI.
 * 2. PENGAJU TIDAK BISA MENYETUJUI SENDIRI.
 * 3. RUSAK, KEDALUWARSA, DAN HILANG DIBEDAKAN — jawabannya menentukan
 *    tindakan yang berbeda.
 * 4. HARGA DIBEKUKAN; nol yang jujur lebih baik daripada angka karangan.
 * 5. KOLOM source KINI PUNYA CHECK — selama ini tidak punya sama sekali.
 */
class StockWriteOffTest extends TestCase
{
    use RefreshDatabase;

    private StockWriteOffService $penghapusan;

    private StockLedger $ledger;

    private ItemService $items;

    private User $petugas;

    private User $kepalaGudang;

    private Item $barang;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->penghapusan = app(StockWriteOffService::class);
        $this->ledger = app(StockLedger::class);
        $this->items = app(ItemService::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-hapus-stok', 'name' => 'Petugas Gudang',
            'password' => 'password', 'is_active' => true,
        ]);
        $this->petugas->roles()->attach(Role::query()->where('code', 'petugas-logistik')->firstOrFail());

        $this->kepalaGudang = User::query()->create([
            'username' => 'uji-kepala-gudang', 'name' => 'Kepala Gudang',
            'password' => 'password', 'is_active' => true,
        ]);

        $kategori = $this->items->createCategory([
            'code' => 'BHP-UTD', 'name' => 'BHP Unit Transfusi Darah', 'is_active' => true,
        ]);

        $this->barang = $this->items->createItem([
            'code' => 'UTD001', 'name' => 'Kantong darah ganda',
            'category_id' => $kategori->id, 'unit_of_measure' => 'pcs',
            'quantity_on_hand' => 0, 'is_active' => true,
        ]);

        $this->ledger->receive($this->barang->id, 100, 'pembelian', null, null, $this->petugas);
    }

    // ============================================== stok & persetujuan

    #[Test]
    public function stok_baru_berkurang_setelah_penghapusan_disetujui(): void
    {
        $pengajuan = $this->ajukan(StockWriteOff::RUSAK, 10);

        // Pengajuan yang langsung memotong stok membuat barang hilang
        // sebelum ada yang membenarkan hilangnya.
        $this->assertSame('100.00', $this->barang->refresh()->quantity_on_hand);

        $this->penghapusan->approve($pengajuan, $this->kepalaGudang);

        $this->assertSame('90.00', $this->barang->refresh()->quantity_on_hand);
    }

    #[Test]
    public function penolakan_tidak_menyentuh_stok(): void
    {
        $pengajuan = $this->ajukan(StockWriteOff::RUSAK, 10);

        $ditolak = $this->penghapusan->reject($pengajuan, 'Barangnya masih layak pakai', $this->kepalaGudang);

        $this->assertSame(StockWriteOff::DITOLAK, $ditolak->status);
        $this->assertSame('100.00', $this->barang->refresh()->quantity_on_hand);
    }

    #[Test]
    public function pengaju_tidak_bisa_menyetujui_sendiri(): void
    {
        $pengajuan = $this->ajukan(StockWriteOff::RUSAK, 5);

        $this->expectException(InventoryException::class);
        $this->expectExceptionMessageMatches('/menyembunyikan stok yang hilang/');

        $this->penghapusan->approve($pengajuan, $this->petugas);
    }

    #[Test]
    public function penghapusan_yang_sudah_diputuskan_tidak_bisa_diputuskan_lagi(): void
    {
        $pengajuan = $this->ajukan(StockWriteOff::RUSAK, 5);
        $this->penghapusan->approve($pengajuan, $this->kepalaGudang);

        $this->expectException(InventoryException::class);
        $this->expectExceptionMessageMatches('/sudah diputuskan/');

        $this->penghapusan->reject($pengajuan->refresh(), 'Berubah pikiran', $this->kepalaGudang);
    }

    #[Test]
    public function basis_data_menolak_disetujui_tanpa_penyetuju(): void
    {
        $pengajuan = $this->ajukan(StockWriteOff::RUSAK, 5);

        $this->expectException(QueryException::class);

        StockWriteOff::query()->whereKey($pengajuan->id)->update([
            'status' => StockWriteOff::DISETUJUI, 'decided_at' => now(),
        ]);
    }

    #[Test]
    public function basis_data_menolak_ditolak_tanpa_alasan(): void
    {
        $pengajuan = $this->ajukan(StockWriteOff::RUSAK, 5);

        $this->expectException(QueryException::class);

        StockWriteOff::query()->whereKey($pengajuan->id)->update([
            'status' => StockWriteOff::DITOLAK, 'decided_at' => now(),
        ]);
    }

    // ============================================== alasan dibedakan

    #[Test]
    public function rusak_kedaluwarsa_dan_hilang_direkap_terpisah(): void
    {
        $this->penghapusan->approve($this->ajukan(StockWriteOff::RUSAK, 5), $this->kepalaGudang);
        $this->penghapusan->approve(
            $this->ajukan(StockWriteOff::KEDALUWARSA, 8, ['expires_on' => now()->subMonth()->toDateString()]),
            $this->kepalaGudang
        );
        $this->penghapusan->approve($this->ajukan(StockWriteOff::HILANG, 2), $this->kepalaGudang);

        $rekap = $this->penghapusan->recapByReason(
            now()->subMonth()->toDateString(),
            now()->addDay()->toDateString()
        );

        // Sebelumnya pertanyaan "berapa banyak yang kita buang tahun ini"
        // tidak bisa dijawab sama sekali.
        $this->assertSame(1, $rekap[StockWriteOff::RUSAK]['jumlah']);
        $this->assertSame(1, $rekap[StockWriteOff::KEDALUWARSA]['jumlah']);
        $this->assertSame(1, $rekap[StockWriteOff::HILANG]['jumlah']);
    }

    #[Test]
    public function barang_kedaluwarsa_wajib_menyebut_tanggal_kedaluwarsanya(): void
    {
        $this->expectException(InventoryException::class);
        $this->expectExceptionMessageMatches('/sejak kapan barangnya menganggur di gudang/');

        $this->ajukan(StockWriteOff::KEDALUWARSA, 5);
    }

    #[Test]
    public function barang_rusak_tidak_menuntut_tanggal_kedaluwarsa(): void
    {
        $pengajuan = $this->ajukan(StockWriteOff::RUSAK, 5);

        $this->assertNull($pengajuan->items->first()->expires_on);
    }

    #[Test]
    public function alasan_di_luar_kosakata_ditolak(): void
    {
        $this->expectException(InventoryException::class);
        $this->expectExceptionMessageMatches("/'dibuang saja' tidak dikenali/");

        $this->ajukan('dibuang saja', 5);
    }

    #[Test]
    public function uraian_alasan_wajib_diisi(): void
    {
        $this->expectException(InventoryException::class);
        $this->expectExceptionMessageMatches('/kerugian gudang dipertanyakan/');

        $this->penghapusan->propose(StockWriteOff::RUSAK, '   ', [
            ['item_id' => $this->barang->id, 'quantity' => 5],
        ], $this->petugas);
    }

    // ============================================== nilai & stok

    #[Test]
    public function harga_dibekukan_saat_penghapusan(): void
    {
        $pengajuan = $this->ajukan(StockWriteOff::RUSAK, 4, ['unit_price' => 12500]);

        $this->assertSame('50000.00', $pengajuan->total_value);
        $this->assertSame(50000.0, $pengajuan->computedValue());
    }

    #[Test]
    public function harga_yang_tidak_diketahui_jadi_nol_dengan_catatan(): void
    {
        $pengajuan = $this->ajukan(StockWriteOff::RUSAK, 4);

        // inventory.items memang tidak menyimpan harga, dan tidak ada
        // riwayat pembelian barang ini. Nol yang jujur lebih baik
        // daripada angka karangan pada catatan kerugian.
        $this->assertSame('0.00', $pengajuan->total_value);
        $this->assertStringContainsString(
            'belum bisa dihitung',
            $pengajuan->items->first()->note
        );
    }

    #[Test]
    public function penghapusan_melebihi_stok_ditolak(): void
    {
        $this->expectException(InventoryException::class);
        $this->expectExceptionMessageMatches('/urusan stok opname, bukan penghapusan/');

        $this->ajukan(StockWriteOff::RUSAK, 500);
    }

    #[Test]
    public function penghapusan_tanpa_barang_ditolak(): void
    {
        $this->expectException(InventoryException::class);
        $this->expectExceptionMessageMatches('/Setidaknya satu barang wajib disebut/');

        $this->penghapusan->propose(StockWriteOff::RUSAK, 'Terkena banjir', [], $this->petugas);
    }

    #[Test]
    public function nama_barang_dibekukan_saat_pengajuan(): void
    {
        $pengajuan = $this->ajukan(StockWriteOff::RUSAK, 5);

        $this->barang->update(['name' => 'Nama baru setelah dikoreksi']);

        $this->assertSame('Kantong darah ganda', $pengajuan->items->first()->item_name);
    }

    // ============================================== jejak pergerakan

    #[Test]
    public function penghapusan_meninggalkan_pergerakan_stok_beralasan(): void
    {
        $pengajuan = $this->ajukan(StockWriteOff::KEDALUWARSA, 6, [
            'expires_on' => now()->subWeek()->toDateString(),
        ]);
        $this->penghapusan->approve($pengajuan, $this->kepalaGudang);

        $gerakan = DB::table('inventory.stock_movements')
            ->where('reference_type', 'stock_write_off')
            ->where('reference_id', $pengajuan->id)
            ->first();

        // Sebelumnya barang rusak hanya bisa dicatat sebagai pengeluaran
        // biasa, tak terbedakan dari yang dipakai melayani pasien.
        $this->assertNotNull($gerakan);
        $this->assertSame('kedaluwarsa', $gerakan->source);
        $this->assertSame('keluar', $gerakan->kind);
    }

    #[Test]
    public function basis_data_kini_menolak_source_di_luar_kosakata(): void
    {
        // Selama ini kolom source TIDAK punya CHECK sama sekali —
        // kosakatanya cuma ditulis di komentar kolom.
        $this->expectException(QueryException::class);

        DB::table('inventory.stock_movements')->insert([
            'item_id' => $this->barang->id,
            'kind' => 'keluar',
            'source' => 'entah-dari-mana',
            'quantity' => -1,
            'balance_after' => 99,
            'moved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function pengajuan_yang_menunggu_bisa_didaftar(): void
    {
        $menunggu = $this->ajukan(StockWriteOff::RUSAK, 3);
        $diputuskan = $this->ajukan(StockWriteOff::RUSAK, 2);
        $this->penghapusan->approve($diputuskan, $this->kepalaGudang);

        $daftar = $this->penghapusan->pending()->pluck('id')->all();

        $this->assertContains($menunggu->id, $daftar);
        $this->assertNotContains($diputuskan->id, $daftar);
    }

    // ---------------------------------------------------------------- fixture

    private function ajukan(string $alasan, float $jumlah, array $baris = []): StockWriteOff
    {
        return $this->penghapusan->propose(
            $alasan,
            'Terkena rembesan air pada rak penyimpanan.',
            [array_merge(['item_id' => $this->barang->id, 'quantity' => $jumlah], $baris)],
            $this->petugas,
        );
    }
}
