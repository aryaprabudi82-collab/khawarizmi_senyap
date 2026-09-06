<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Database\Seeders\ReceivableCategorySeeder;
use App\Modules\Finance\Models\OtherReceivable;
use App\Modules\Finance\Models\Payable;
use App\Modules\Finance\Models\ReceivableCategory;
use App\Modules\Finance\Services\FinanceException;
use App\Modules\Finance\Services\OtherReceivableService;
use App\Modules\Finance\Services\PayableService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Piutang non-pasien & beban hutang lain (domain K item C).
 *
 * Yang paling perlu dikunci: kedua arah TIDAK pernah tercampur. Piutang
 * (uang masuk) dan beban hutang lain (uang keluar) tinggal di mekanisme
 * berbeda meski Khanza menaruhnya satu menu — kalau tercampur, cepat atau
 * lambat ada laporan yang menjumlahkan hutang bersama piutang.
 */
class OtherReceivableTest extends TestCase
{
    use RefreshDatabase;

    private OtherReceivableService $piutang;
    private PayableService $hutang;
    private User $keuangan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReceivableCategorySeeder::class]);

        $this->piutang = app(OtherReceivableService::class);
        $this->hutang = app(PayableService::class);

        $this->keuangan = User::query()->create([
            'username' => 'uji-keuangan-piutang', 'name' => 'Petugas Keuangan', 'password' => 'password', 'is_active' => true,
        ]);
        $this->keuangan->roles()->attach(Role::query()->where('code', 'petugas-keuangan')->firstOrFail());
    }

    // ------------------------------------------------------------- pencatatan

    #[Test]
    public function jenis_disalin_dari_kategori_bukan_dari_masukan(): void
    {
        $p = $this->catat('PPU-01', 'Budi', 5_000_000, kirimJenis: ReceivableCategory::JASA_PERUSAHAAN);

        $this->assertSame(ReceivableCategory::PEMINJAMAN_UANG, $p->kind, 'Jenis kategori yang menang');
    }

    #[Test]
    public function jatuh_tempo_tidak_boleh_mendahului_tanggal_piutang(): void
    {
        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('mendahului');

        $this->piutang->record([
            'category_id' => $this->kategori('PJP-01')->id,
            'debtor_name' => 'PT Uji',
            'issued_on' => now()->toDateString(),
            'due_date' => now()->subDays(5)->toDateString(),
            'amount' => 100_000,
            'description' => 'MCU',
        ]);
    }

    // -------------------------------------------------------------- pelunasan

    #[Test]
    public function piutang_bisa_dicicil_dan_sisanya_selalu_dihitung(): void
    {
        $p = $this->catat('PJP-01', 'PT Sehat', 10_000_000);

        $this->terima($p, 4_000_000);
        $this->assertSame(6_000_000.0, $p->refresh()->remaining());

        $this->terima($p->refresh(), 6_000_000);
        $this->assertSame(0.0, $p->refresh()->remaining());
        $this->assertSame(OtherReceivable::LUNAS, $p->refresh()->status);
    }

    #[Test]
    public function pembayaran_melebihi_sisa_ditolak(): void
    {
        $p = $this->catat('PJP-01', 'PT Sehat', 1_000_000);
        $this->terima($p, 800_000);

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('melebihi sisa');

        $this->terima($p->refresh(), 300_000);
    }

    /**
     * Piutang yang dicicil berkali-kali tidak boleh tergandakan pada
     * laporan — pembayaran dijumlahkan lewat subquery, bukan join.
     */
    #[Test]
    public function piutang_yang_dicicil_berkali_kali_tidak_tergandakan(): void
    {
        $p = $this->catat('PJP-01', 'PT Sehat', 10_000_000);

        $this->terima($p, 1_000_000);
        $this->terima($p->refresh(), 2_000_000);
        $this->terima($p->refresh(), 3_000_000);

        $perPihak = $this->piutang->byDebtor();

        $this->assertCount(1, $perPihak, 'Satu pihak, satu baris — bukan tiga');
        $this->assertSame(1, (int) $perPihak->first()->piutang);
        $this->assertSame(4_000_000.0, (float) $perPihak->first()->sisa);
    }

    // ------------------------------------------------------------ penghapusan

    /** Penghapusan tidak menghapus barisnya — keputusan itu harus bisa ditelusuri. */
    #[Test]
    public function piutang_dihapuskan_keluar_dari_hitungan_tapi_tetap_terlihat(): void
    {
        $p = $this->catat('PJP-01', 'PT Bangkrut', 5_000_000);
        $this->catat('PJP-02', 'PT Sehat', 3_000_000);

        $this->piutang->writeOff($p, 'perusahaan pailit');

        $this->assertCount(1, $this->piutang->outstanding(), 'Yang dihapuskan tidak lagi dihitung');
        $this->assertSame(3_000_000.0, (float) $this->piutang->byKind()->sum('sisa'));

        $dihapus = $this->piutang->writtenOff();
        $this->assertCount(1, $dihapus);
        $this->assertSame('perusahaan pailit', $dihapus->first()->write_off_reason);
    }

    #[Test]
    public function piutang_dihapuskan_tidak_bisa_menerima_pembayaran(): void
    {
        $p = $this->catat('PJP-01', 'PT Bangkrut', 5_000_000);
        $this->piutang->writeOff($p, 'pailit');

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('dihapuskan');

        $this->terima($p->refresh(), 100_000);
    }

    #[Test]
    public function piutang_lunas_tidak_bisa_dihapuskan(): void
    {
        $p = $this->catat('PJP-01', 'PT Sehat', 1_000_000);
        $this->terima($p, 1_000_000);

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('piutang berjalan');

        $this->piutang->writeOff($p->refresh(), 'coba-coba');
    }

    // ---------------------------------------------------- pemisahan dua arah

    /**
     * Inti item ini: beban hutang lain TIDAK muncul di angka piutang, dan
     * sebaliknya. Keduanya arah berlawanan dan tidak pernah dijumlahkan.
     */
    #[Test]
    public function beban_hutang_lain_tidak_tercampur_ke_angka_piutang(): void
    {
        $this->catat('PJP-01', 'PT Sehat', 5_000_000);
        $this->bebanHutang('Koperasi Pegawai', 8_000_000);

        $this->assertSame(5_000_000.0, (float) $this->piutang->byKind()->sum('sisa'),
            'Piutang tidak boleh ikut memuat hutang');

        $this->assertSame(8_000_000.0, (float) $this->hutang->outstanding('lain')->sum('sisa'));
        $this->assertCount(0, $this->hutang->outstanding('farmasi'),
            'Hutang lain bukan hutang vendor farmasi');
    }

    /**
     * Beban hutang lain langsung diakui: tidak ada vendor yang menitipkan
     * apa pun, jadi memaksanya menunggu validasi berarti meminta orang
     * memvalidasi catatannya sendiri.
     */
    #[Test]
    public function beban_hutang_lain_langsung_tervalidasi(): void
    {
        $h = $this->bebanHutang('Koperasi Pegawai', 8_000_000);

        $this->assertSame(Payable::TERVALIDASI, $h->status);
        $this->assertNotNull($h->validated_at);
        $this->assertStringStartsWith('HTL', $h->payable_number, 'Bernomor beda dari hutang vendor');

        // Dan langsung bisa dibayar tanpa langkah validasi.
        $this->hutang->pay($h, ['paid_on' => now()->toDateString(), 'amount' => 8_000_000]);
        $this->assertSame(Payable::LUNAS, $h->refresh()->status);
    }

    /** Hutang vendor TETAP harus divalidasi — aturan lama tidak ikut longgar. */
    #[Test]
    public function hutang_vendor_tetap_wajib_divalidasi(): void
    {
        $h = $this->hutang->submit([
            'source_context' => 'farmasi',
            'supplier_name' => 'PT Farma',
            'invoice_number' => 'INV-9',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'amount' => 1_000_000,
        ]);

        $this->assertSame(Payable::DITITIPKAN, $h->status);

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('divalidasi lebih dulu');

        $this->hutang->pay($h, ['paid_on' => now()->toDateString(), 'amount' => 100_000]);
    }

    // ---------------------------------------------------------------- laporan

    #[Test]
    public function penyaring_jenis_berlaku_pada_seluruh_potongan(): void
    {
        $this->catat('PJP-01', 'PT Sehat', 5_000_000);
        $this->catat('PPU-01', 'Budi', 2_000_000);

        $this->assertCount(2, $this->piutang->outstanding());
        $this->assertCount(1, $this->piutang->outstanding(ReceivableCategory::JASA_PERUSAHAAN));
        $this->assertCount(1, $this->piutang->byDebtor(ReceivableCategory::PEMINJAMAN_UANG));
        $this->assertSame(5_000_000.0, (float) $this->piutang->aging(ReceivableCategory::JASA_PERUSAHAAN)->sum('sisa'));
    }

    #[Test]
    public function umur_piutang_memisahkan_yang_belum_jatuh_tempo(): void
    {
        $this->catat('PJP-01', 'PT Sehat', 1_000_000, tempo: now()->addDays(20)->toDateString());
        $this->catat('PJP-02', 'PT Telat', 2_000_000, tempo: now()->subDays(45)->toDateString());

        $umur = $this->piutang->aging()->keyBy('kelompok');

        $this->assertSame(1_000_000.0, (float) $umur['belum jatuh tempo']->sisa);
        $this->assertSame(2_000_000.0, (float) $umur['31-60 hari']->sisa);
    }

    #[Test]
    public function piutang_yang_belum_dipetakan_ke_akun_dilaporkan(): void
    {
        $this->catat('PJP-01', 'PT Sehat', 5_000_000);

        $this->assertSame(5_000_000.0, $this->piutang->unmappedTotal(), 'Seeder sengaja tidak memetakan');
    }

    // ----------------------------------------------------------------- layar

    #[Test]
    public function layar_piutang_lain_hanya_untuk_petugas_keuangan(): void
    {
        $this->actingAs($this->keuangan)->get(route('piutang-lain.index'))->assertOk();

        $dokter = User::query()->create([
            'username' => 'uji-dokter-piutang', 'name' => 'Dokter', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)->get(route('piutang-lain.index'))->assertForbidden();
    }

    #[Test]
    public function piutang_bisa_dicatat_dan_ditagih_lewat_http(): void
    {
        $this->actingAs($this->keuangan)
            ->post(route('piutang-lain.simpan'), [
                'category_id' => $this->kategori('PJP-01')->id,
                'debtor_name' => 'PT HTTP',
                'issued_on' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'amount' => 2_000_000,
                'description' => 'MCU karyawan',
            ])
            ->assertRedirect();

        $p = OtherReceivable::query()->firstOrFail();

        $this->actingAs($this->keuangan)
            ->post(route('piutang-lain.bayar', $p->id), ['paid_on' => now()->toDateString(), 'amount' => 2_000_000])
            ->assertRedirect();

        $this->assertSame(OtherReceivable::LUNAS, $p->refresh()->status);
    }

    #[Test]
    public function layar_menyatakan_kedua_arah_tidak_dijumlahkan(): void
    {
        $this->actingAs($this->keuangan)
            ->get(route('piutang-lain.index'))
            ->assertOk()
            ->assertSee('dua arah yang berlawanan, dan keduanya tidak pernah dijumlahkan', false);
    }

    // ------------------------------------------------------------------ bantu

    private function kategori(string $code): ReceivableCategory
    {
        return ReceivableCategory::query()->where('code', $code)->firstOrFail();
    }

    private function catat(
        string $code,
        string $pihak,
        float $nilai,
        ?string $tempo = null,
        ?string $kirimJenis = null,
    ): OtherReceivable {
        $tempo ??= now()->addDays(30)->toDateString();

        return $this->piutang->record([
            'category_id' => $this->kategori($code)->id,
            'debtor_name' => $pihak,
            'issued_on' => \Carbon\CarbonImmutable::parse($tempo)->subDays(30)->toDateString(),
            'due_date' => $tempo,
            'amount' => $nilai,
            'description' => 'Uji piutang ' . $code,
            // Sengaja dikirim jenis yang salah pada satu tes — harus diabaikan.
            'kind' => $kirimJenis,
        ], $this->keuangan->id);
    }

    private function terima(OtherReceivable $p, float $nilai): void
    {
        $this->piutang->collect($p, [
            'paid_on' => now()->toDateString(),
            'amount' => $nilai,
        ], $this->keuangan->id, $this->keuangan->name);
    }

    private function bebanHutang(string $kepada, float $nilai): Payable
    {
        return $this->hutang->submit([
            'source_context' => 'lain',
            'supplier_name' => $kepada,
            'invoice_number' => 'PJJ-' . substr(md5($kepada . $nilai), 0, 6),
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(60)->toDateString(),
            'amount' => $nilai,
        ], $this->keuangan->id);
    }
}
