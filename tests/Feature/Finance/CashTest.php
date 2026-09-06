<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Database\Seeders\CashCategorySeeder;
use App\Modules\Finance\Models\CashCategory;
use App\Modules\Finance\Models\CashTransaction;
use App\Modules\Finance\Services\CashService;
use App\Modules\Finance\Services\FinanceException;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Kas harian (domain K item A).
 *
 * Yang paling perlu dikunci: nilai selalu positif dengan arah dibaca dari
 * kategorinya (bukan dikirim terpisah, yang membuka celah pengeluaran
 * tercatat sebagai pemasukan), dan transaksi yang dibatalkan hilang dari
 * SETIAP angka — bukan sebagian.
 */
class CashTest extends TestCase
{
    use RefreshDatabase;

    private CashService $kas;
    private User $keuangan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, CashCategorySeeder::class]);

        $this->kas = app(CashService::class);

        $this->keuangan = User::query()->create([
            'username' => 'uji-keuangan-kas', 'name' => 'Petugas Keuangan', 'password' => 'password', 'is_active' => true,
        ]);
        $this->keuangan->roles()->attach(Role::query()->where('code', 'petugas-keuangan')->firstOrFail());
    }

    // ------------------------------------------------------------ pencatatan

    #[Test]
    public function transaksi_kas_bernomor_sesuai_arahnya(): void
    {
        $masuk = $this->catat('KM-01', 500_000);
        $keluar = $this->catat('KK-01', 200_000);

        $this->assertStringStartsWith('KM', $masuk->transaction_number);
        $this->assertStringStartsWith('KK', $keluar->transaction_number);
    }

    /**
     * Inti keamanan angka: arah TIDAK diterima dari pemanggil, melainkan
     * disalin dari kategorinya. Kalau boleh dikirim terpisah, pengeluaran
     * bisa tercatat sebagai pemasukan tanpa melanggar satu aturan pun.
     */
    #[Test]
    public function arah_disalin_dari_kategori_bukan_dari_masukan(): void
    {
        $trx = $this->kas->record([
            'category_id' => $this->kategori('KK-01')->id,
            'transaction_date' => now()->toDateString(),
            'amount' => 100_000,
            'description' => 'Bayar listrik',
            // Sengaja dikirim arah yang salah — harus diabaikan.
            'direction' => CashCategory::MASUK,
        ]);

        $this->assertSame(CashCategory::KELUAR, $trx->direction, 'Arah kategori yang menang, bukan masukan');
    }

    #[Test]
    public function nilai_nol_atau_negatif_ditolak(): void
    {
        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('lebih dari nol');

        $this->catat('KM-01', 0);
    }

    #[Test]
    public function kategori_nonaktif_ditolak(): void
    {
        $this->kategori('KM-02')->update(['is_active' => false]);

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('tidak aktif');

        $this->catat('KM-02', 50_000);
    }

    #[Test]
    public function nilai_disimpan_positif_untuk_kedua_arah(): void
    {
        $this->catat('KM-01', 500_000);
        $this->catat('KK-01', 200_000);

        $this->assertSame(
            0,
            CashTransaction::query()->where('amount', '<', 0)->count(),
            'Pengeluaran tidak boleh disimpan negatif'
        );
    }

    // --------------------------------------------------------------- laporan

    #[Test]
    public function ringkasan_memisahkan_masuk_dan_keluar(): void
    {
        $this->catat('KM-01', 500_000);
        $this->catat('KM-02', 300_000);
        $this->catat('KK-01', 200_000);

        $r = $this->kas->summary($this->hariIni(), $this->hariIni());

        $this->assertSame(800_000.0, $r->masuk);
        $this->assertSame(200_000.0, $r->keluar);
        $this->assertSame(600_000.0, $r->selisih);
        $this->assertSame(3, $r->transaksi);
    }

    /**
     * Pembatalan harus hilang dari SETIAP angka, bukan sebagian —
     * kesalahan yang sudah pernah terjadi di laporan pharmacy dan rekap
     * billing, dan yang paling sulit ketahuan karena angkanya tetap wajar.
     */
    #[Test]
    public function transaksi_dibatalkan_hilang_dari_setiap_angka(): void
    {
        $this->catat('KM-01', 500_000);
        $batal = $this->catat('KM-02', 999_000);

        $this->kas->cancel($batal, 'salah input');

        $hariIni = $this->hariIni();

        $this->assertSame(500_000.0, $this->kas->summary($hariIni, $hariIni)->masuk);
        $this->assertSame(500_000.0, (float) $this->kas->dailyCashflow($hariIni, $hariIni)->sum('masuk'));
        $this->assertSame(500_000.0, (float) $this->kas->byCategory($hariIni, $hariIni)->sum('nilai'));
        $this->assertCount(1, $this->kas->transactions($hariIni, $hariIni));
    }

    /** Yang dibatalkan tetap bisa ditelusuri berikut alasannya. */
    #[Test]
    public function transaksi_dibatalkan_tetap_terlihat_terpisah(): void
    {
        $batal = $this->catat('KM-01', 700_000);
        $this->kas->cancel($batal, 'kuitansi ganda');

        $daftar = $this->kas->cancelledTransactions($this->hariIni(), $this->hariIni());

        $this->assertCount(1, $daftar);
        $this->assertSame('kuitansi ganda', $daftar->first()->cancellation_reason);
    }

    #[Test]
    public function transaksi_tidak_bisa_dibatalkan_dua_kali(): void
    {
        $trx = $this->catat('KM-01', 100_000);
        $this->kas->cancel($trx, 'pertama');

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('sudah dibatalkan');

        $this->kas->cancel($trx->refresh(), 'kedua');
    }

    /** Kas yang kategorinya belum dipetakan harus terlihat, bukan lenyap diam-diam. */
    #[Test]
    public function kas_yang_belum_dipetakan_ke_akun_dilaporkan(): void
    {
        $this->catat('KM-01', 400_000);
        $this->catat('KM-02', 100_000);

        $hariIni = $this->hariIni();
        $this->assertSame(500_000.0, $this->kas->unmappedTotal($hariIni, $hariIni), 'Seeder sengaja tidak memetakan');

        $akun = DB::table('finance.chart_of_accounts')->insertGetId([
            'code' => '4-9000', 'name' => 'Pendapatan Lain', 'type' => 'pendapatan',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->kategori('KM-01')->update(['account_id' => $akun]);

        $this->assertSame(100_000.0, $this->kas->unmappedTotal($hariIni, $hariIni), 'Tinggal yang belum dipetakan');
    }

    #[Test]
    public function penyaring_arah_berlaku_pada_rekap_kategori(): void
    {
        $this->catat('KM-01', 500_000);
        $this->catat('KK-01', 200_000);

        $hariIni = $this->hariIni();

        $this->assertCount(2, $this->kas->byCategory($hariIni, $hariIni));
        $this->assertCount(1, $this->kas->byCategory($hariIni, $hariIni, 'masuk'));
        $this->assertCount(1, $this->kas->byCategory($hariIni, $hariIni, 'keluar'));
    }

    #[Test]
    public function penyaring_kategori_berlaku_pada_seluruh_potongan(): void
    {
        $this->catat('KM-01', 500_000);
        $this->catat('KM-02', 300_000);

        $hariIni = $this->hariIni();
        $id = $this->kategori('KM-01')->id;

        $this->assertSame(800_000.0, $this->kas->summary($hariIni, $hariIni)->masuk);
        $this->assertSame(500_000.0, $this->kas->summary($hariIni, $hariIni, $id)->masuk);
        $this->assertSame(500_000.0, (float) $this->kas->dailyCashflow($hariIni, $hariIni, $id)->sum('masuk'));
        $this->assertCount(1, $this->kas->transactions($hariIni, $hariIni, $id));
    }

    // ----------------------------------------------------------------- layar

    #[Test]
    public function layar_kas_hanya_untuk_petugas_keuangan(): void
    {
        $this->actingAs($this->keuangan)->get(route('kas.index'))->assertOk();

        $dokter = User::query()->create([
            'username' => 'uji-dokter-kas', 'name' => 'Dokter', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)->get(route('kas.index'))->assertForbidden();
    }

    #[Test]
    public function transaksi_bisa_dicatat_lewat_http(): void
    {
        $this->actingAs($this->keuangan)
            ->post(route('kas.simpan'), [
                'category_id' => $this->kategori('KK-02')->id,
                'transaction_date' => $this->hariIni(),
                'amount' => 150_000,
                'description' => 'Bahan bakar ambulans',
            ])
            ->assertRedirect();

        $trx = CashTransaction::query()->firstOrFail();

        $this->assertSame(CashCategory::KELUAR, $trx->direction);
        $this->assertSame('150000.00', $trx->amount);
        $this->assertSame($this->keuangan->id, $trx->recorded_by);
    }

    #[Test]
    public function layar_kas_menyatakan_kas_yang_belum_dipetakan(): void
    {
        $this->catat('KM-01', 250_000);

        $this->actingAs($this->keuangan)
            ->get(route('kas.index', ['dari' => $this->hariIni(), 'sampai' => $this->hariIni()]))
            ->assertOk()
            ->assertSee('belum terpetakan ke bagan akun', false)
            ->assertSee('Sewa Lahan', false);
    }

    // ------------------------------------------------------------------ bantu

    private function hariIni(): string
    {
        return now()->toDateString();
    }

    private function kategori(string $code): CashCategory
    {
        return CashCategory::query()->where('code', $code)->firstOrFail();
    }

    private function catat(string $code, float $nilai): CashTransaction
    {
        return $this->kas->record([
            'category_id' => $this->kategori($code)->id,
            'transaction_date' => $this->hariIni(),
            'amount' => $nilai,
            'description' => 'Uji kas ' . $code,
        ], $this->keuangan->id, $this->keuangan->name);
    }
}
