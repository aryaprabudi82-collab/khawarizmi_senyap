<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Models\Payable;
use App\Modules\Finance\Services\FinanceException;
use App\Modules\Finance\Services\PayableService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Hutang vendor (domain K item B).
 *
 * Tiga hal yang paling perlu dikunci: nilai DIBEKUKAN saat validasi,
 * sisa SELALU dihitung (tidak pernah disimpan), dan faktur yang dicicil
 * berkali-kali tidak boleh tergandakan pada laporan.
 */
class PayableTest extends TestCase
{
    use RefreshDatabase;

    private PayableService $hutang;
    private User $keuangan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class]);

        $this->hutang = app(PayableService::class);

        $this->keuangan = User::query()->create([
            'username' => 'uji-keuangan-hutang', 'name' => 'Petugas Keuangan', 'password' => 'password', 'is_active' => true,
        ]);
        $this->keuangan->roles()->attach(Role::query()->where('code', 'petugas-keuangan')->firstOrFail());
    }

    // ------------------------------------------------------------- pencatatan

    #[Test]
    public function faktur_dititipkan_belum_dihitung_sebagai_hutang(): void
    {
        $this->titip('PT Farma', 'INV-001', 5_000_000);

        $this->assertCount(0, $this->hutang->outstanding(), 'Yang baru dititipkan belum diakui sebagai hutang');
        $this->assertCount(1, $this->hutang->pending());
    }

    #[Test]
    public function faktur_masuk_hitungan_setelah_divalidasi(): void
    {
        $f = $this->titip('PT Farma', 'INV-001', 5_000_000);
        $this->hutang->validate($f);

        $terutang = $this->hutang->outstanding();

        $this->assertCount(1, $terutang);
        $this->assertSame(5_000_000.0, (float) $terutang->first()->sisa);
    }

    /** Nilai boleh dikoreksi saat validasi — itulah saat pembekuannya. */
    #[Test]
    public function nilai_bisa_dikoreksi_saat_validasi_lalu_dibekukan(): void
    {
        $f = $this->titip('PT Farma', 'INV-001', 5_000_000);
        $f = $this->hutang->validate($f, 4_750_000);

        $this->assertSame('4750000.00', $f->amount);

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('masih dititipkan');

        $this->hutang->validate($f, 9_000_000);
    }

    #[Test]
    public function faktur_ganda_dari_vendor_yang_sama_ditolak(): void
    {
        $this->titip('PT Farma', 'INV-001', 1_000_000);

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('sudah pernah dititipkan');

        $this->titip('PT Farma', 'INV-001', 1_000_000);
    }

    /** Faktur yang ditolak boleh dititipkan ulang setelah diperbaiki. */
    #[Test]
    public function faktur_yang_ditolak_boleh_dititipkan_ulang(): void
    {
        $f = $this->titip('PT Farma', 'INV-001', 1_000_000);
        $this->hutang->reject($f, 'harga tidak sesuai PO');

        $baru = $this->titip('PT Farma', 'INV-001', 900_000);

        $this->assertSame(Payable::DITITIPKAN, $baru->status);
    }

    #[Test]
    public function jatuh_tempo_tidak_boleh_mendahului_tanggal_faktur(): void
    {
        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('mendahului');

        $this->hutang->submit([
            'source_context' => 'farmasi',
            'supplier_name' => 'PT Farma',
            'invoice_number' => 'INV-XX',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->subDays(5)->toDateString(),
            'amount' => 100_000,
        ]);
    }

    // -------------------------------------------------------------- pelunasan

    #[Test]
    public function faktur_belum_divalidasi_tidak_bisa_dibayar(): void
    {
        $f = $this->titip('PT Farma', 'INV-001', 1_000_000);

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('divalidasi lebih dulu');

        $this->bayar($f, 500_000);
    }

    /** Sisa dihitung, bukan disimpan — dicicil dua kali tetap benar. */
    #[Test]
    public function hutang_bisa_dicicil_dan_sisanya_selalu_dihitung(): void
    {
        $f = $this->hutang->validate($this->titip('PT Farma', 'INV-001', 10_000_000));

        $this->bayar($f, 4_000_000);
        $this->assertSame(6_000_000.0, $f->refresh()->remaining());

        $this->bayar($f->refresh(), 6_000_000);
        $this->assertSame(0.0, $f->refresh()->remaining());
        $this->assertSame(Payable::LUNAS, $f->refresh()->status);
    }

    /**
     * Pembayaran melebihi sisa ditolak: sisa negatif akan diam-diam
     * mengurangi total hutang rumah sakit saat dijumlahkan.
     */
    #[Test]
    public function pembayaran_melebihi_sisa_ditolak(): void
    {
        $f = $this->hutang->validate($this->titip('PT Farma', 'INV-001', 1_000_000));
        $this->bayar($f, 800_000);

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('melebihi sisa');

        $this->bayar($f->refresh(), 300_000);
    }

    #[Test]
    public function faktur_lunas_tidak_bisa_dibayar_lagi(): void
    {
        $f = $this->hutang->validate($this->titip('PT Farma', 'INV-001', 1_000_000));
        $this->bayar($f, 1_000_000);

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('sudah lunas');

        $this->bayar($f->refresh(), 1);
    }

    #[Test]
    public function faktur_lunas_keluar_dari_daftar_terutang(): void
    {
        $f = $this->hutang->validate($this->titip('PT Farma', 'INV-001', 1_000_000));
        $this->hutang->validate($this->titip('PT Alkes', 'INV-002', 2_000_000));

        $this->bayar($f, 1_000_000);

        $terutang = $this->hutang->outstanding();

        $this->assertCount(1, $terutang);
        $this->assertSame('PT Alkes', $terutang->first()->supplier_name);
    }

    // ---------------------------------------------------------------- laporan

    /**
     * Inti bentuk kuerinya: faktur yang dicicil berkali-kali TIDAK boleh
     * tergandakan pada laporan — pelajaran yang sama seperti keanggotaan
     * surveilans di domain J. Pembayaran dijumlahkan lewat subquery,
     * bukan join.
     */
    #[Test]
    public function faktur_yang_dicicil_berkali_kali_tidak_tergandakan(): void
    {
        $f = $this->hutang->validate($this->titip('PT Farma', 'INV-001', 10_000_000));

        $this->bayar($f, 1_000_000);
        $this->bayar($f->refresh(), 2_000_000);
        $this->bayar($f->refresh(), 3_000_000);

        $perVendor = $this->hutang->bySupplier();

        $this->assertCount(1, $perVendor, 'Satu vendor, satu baris — bukan tiga');
        $this->assertSame(1, (int) $perVendor->first()->faktur, 'Satu faktur, bukan tiga');
        $this->assertSame(10_000_000.0, (float) $perVendor->first()->nilai, 'Nilai faktur tidak tergandakan');
        $this->assertSame(4_000_000.0, (float) $perVendor->first()->sisa);
    }

    /** Yang belum jatuh tempo bukan tunggakan — dipisahkan sendiri. */
    #[Test]
    public function umur_hutang_memisahkan_yang_belum_jatuh_tempo(): void
    {
        $belum = $this->titip('PT Farma', 'INV-001', 1_000_000, now()->addDays(20)->toDateString());
        $lewat = $this->titip('PT Alkes', 'INV-002', 2_000_000, now()->subDays(45)->toDateString());

        $this->hutang->validate($belum);
        $this->hutang->validate($lewat);

        $umur = $this->hutang->aging()->keyBy('kelompok');

        $this->assertSame(1_000_000.0, (float) $umur['belum jatuh tempo']->sisa);
        $this->assertSame(2_000_000.0, (float) $umur['31-60 hari']->sisa);
    }

    #[Test]
    public function ringkasan_per_rantai_pengadaan_memisahkan_sumbernya(): void
    {
        $this->hutang->validate($this->titip('PT Farma', 'INV-001', 1_000_000, null, 'farmasi'));
        $this->hutang->validate($this->titip('PT Boga', 'INV-002', 3_000_000, null, 'dapur'));

        $perSumber = $this->hutang->bySource()->keyBy('source_context');

        $this->assertSame(1_000_000.0, (float) $perSumber['farmasi']->sisa);
        $this->assertSame(3_000_000.0, (float) $perSumber['dapur']->sisa);
    }

    #[Test]
    public function penyaring_rantai_berlaku_pada_seluruh_potongan(): void
    {
        $this->hutang->validate($this->titip('PT Farma', 'INV-001', 1_000_000, null, 'farmasi'));
        $this->hutang->validate($this->titip('PT Boga', 'INV-002', 3_000_000, null, 'dapur'));

        $this->assertCount(2, $this->hutang->outstanding());
        $this->assertCount(1, $this->hutang->outstanding('farmasi'));
        $this->assertCount(1, $this->hutang->bySupplier('farmasi'));
        $this->assertSame(1_000_000.0, (float) $this->hutang->aging('farmasi')->sum('sisa'));
    }

    #[Test]
    public function hutang_yang_belum_dipetakan_ke_akun_dilaporkan(): void
    {
        $this->hutang->validate($this->titip('PT Farma', 'INV-001', 1_000_000));

        $this->assertSame(1_000_000.0, $this->hutang->unmappedTotal());
    }

    // ----------------------------------------------------------------- layar

    #[Test]
    public function layar_hutang_hanya_untuk_petugas_keuangan(): void
    {
        $this->actingAs($this->keuangan)->get(route('hutang.index'))->assertOk();

        $apoteker = User::query()->create([
            'username' => 'uji-apoteker-hutang', 'name' => 'Apoteker', 'password' => 'password', 'is_active' => true,
        ]);
        $apoteker->roles()->attach(Role::query()->where('code', 'apoteker')->firstOrFail());

        $this->actingAs($apoteker)->get(route('hutang.index'))->assertForbidden();
    }

    /**
     * Apoteker memegang bayar_pemesanan_obat untuk penerimaan obat, dan
     * itu TIDAK boleh memberinya kewenangan atas buku hutang rumah sakit.
     */
    #[Test]
    public function apoteker_tidak_bisa_memvalidasi_hutang_rumah_sakit(): void
    {
        $f = $this->titip('PT Farma', 'INV-001', 1_000_000);

        $apoteker = User::query()->create([
            'username' => 'uji-apoteker-validasi', 'name' => 'Apoteker', 'password' => 'password', 'is_active' => true,
        ]);
        $apoteker->roles()->attach(Role::query()->where('code', 'apoteker')->firstOrFail());

        $this->actingAs($apoteker)
            ->post(route('hutang.validasi', $f->id))
            ->assertForbidden();
    }

    #[Test]
    public function faktur_bisa_dititipkan_dan_dibayar_lewat_http(): void
    {
        $this->actingAs($this->keuangan)
            ->post(route('hutang.simpan'), [
                'source_context' => 'dapur',
                'supplier_name' => 'PT Boga',
                'invoice_number' => 'INV-HTTP',
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'amount' => 2_000_000,
            ])
            ->assertRedirect();

        $f = Payable::query()->firstOrFail();

        $this->actingAs($this->keuangan)->post(route('hutang.validasi', $f->id))->assertRedirect();
        $this->actingAs($this->keuangan)
            ->post(route('hutang.bayar', $f->id), ['paid_on' => now()->toDateString(), 'amount' => 2_000_000])
            ->assertRedirect();

        $this->assertSame(Payable::LUNAS, $f->refresh()->status);
        $this->assertSame(0.0, $f->remaining());
    }

    // ------------------------------------------------------------------ bantu

    private function titip(
        string $vendor,
        string $faktur,
        float $nilai,
        ?string $jatuhTempo = null,
        string $sumber = 'farmasi',
    ): Payable {
        $tempo = $jatuhTempo ?? now()->addDays(30)->toDateString();

        // Faktur selalu mendahului jatuh temponya — faktur yang sudah lewat
        // tempo 45 hari tentu tanggalnya juga lampau, bukan kemarin.
        $tanggal = \Carbon\CarbonImmutable::parse($tempo)->subDays(30)->toDateString();

        return $this->hutang->submit([
            'source_context' => $sumber,
            'supplier_name' => $vendor,
            'invoice_number' => $faktur,
            'invoice_date' => $tanggal,
            'due_date' => $tempo,
            'amount' => $nilai,
        ], $this->keuangan->id);
    }

    private function bayar(Payable $f, float $nilai): void
    {
        $this->hutang->pay($f, [
            'paid_on' => now()->toDateString(),
            'amount' => $nilai,
        ], $this->keuangan->id, $this->keuangan->name);
    }
}
