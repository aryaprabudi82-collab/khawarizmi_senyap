<?php

namespace Tests\Feature\Finance;

use App\Modules\Billing\Models\ManualAdjustment;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Catalog\Models\Payer;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\AccountMapping;
use App\Modules\Finance\Models\PeriodClosing;
use App\Modules\Finance\Services\AccountingReportService;
use App\Modules\Finance\Services\FinanceException;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Organization\Models\Unit;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Domain I item E: pemetaan uang ke bagan akun dan penutupan periode.
 *
 * Yang diuji di sini mekanismenya, bukan kebenaran bagan akunnya — bagan
 * akun yang terpasang memang baru empat akun contoh dari seeder. Justru
 * karena itu perilaku "belum dipetakan" ikut diuji: uang yang tidak punya
 * akun harus terlihat, bukan hilang diam-diam.
 */
class AccountingTest extends TestCase
{
    use RefreshDatabase;

    private AccountingReportService $akuntansi;
    private InvoiceService $invoices;
    private User $keuangan;
    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);
        $this->seed(\App\Modules\Finance\Database\Seeders\ChartOfAccountsSeeder::class);

        $this->akuntansi = app(AccountingReportService::class);
        $this->invoices = app(InvoiceService::class);

        $this->keuangan = $this->pengguna('uji-keuangan-akun', 'petugas-keuangan');
        $this->kasir = $this->pengguna('uji-kasir-akun', 'kasir');
    }

    #[Test]
    public function uang_tanpa_pemetaan_tampil_sebagai_belum_dipetakan_bukan_hilang(): void
    {
        $this->bayar(150_000, 'tunai');

        $hariIni = now()->toDateString();
        $baris = $this->akuntansi->paymentsByAccount($hariIni, $hariIni);

        $this->assertCount(1, $baris);
        $this->assertNull($baris->first()['account_code'], 'Belum ada pemetaan, jadi akunnya kosong');
        $this->assertSame(150_000.0, $baris->first()['total'], 'Tapi uangnya tetap terlihat penuh');
        $this->assertGreaterThan(0, $this->akuntansi->unmappedTotal($hariIni, $hariIni));
    }

    #[Test]
    public function pemetaan_cara_bayar_menempelkan_akun_pada_uang_masuk(): void
    {
        $this->bayar(200_000, 'qris');

        AccountMapping::query()->create([
            'kind' => AccountMapping::KIND_CARA_BAYAR,
            'key' => 'qris',
            'account_id' => Account::query()->where('type', Account::TYPE_KAS)->value('id'),
            'updated_by' => $this->keuangan->id,
        ]);

        $hariIni = now()->toDateString();
        $baris = $this->akuntansi->paymentsByAccount($hariIni, $hariIni)->firstWhere('key', 'qris');

        $this->assertSame('1-1000', $baris['account_code']);
        $this->assertSame(200_000.0, $baris['total']);
    }

    #[Test]
    public function pendapatan_dikelompokkan_per_sumber_dan_bisa_dipetakan(): void
    {
        $tagihan = $this->tagihan();
        $this->invoices->addAdjustment($tagihan, ManualAdjustment::KIND_TAMBAHAN, 'Ambulans', 300_000, $this->kasir);

        AccountMapping::query()->create([
            'kind' => AccountMapping::KIND_SUMBER,
            'key' => 'penyesuaian',
            'account_id' => Account::query()->where('type', Account::TYPE_PENDAPATAN)->value('id'),
            'updated_by' => $this->keuangan->id,
        ]);

        $hariIni = now()->toDateString();
        $baris = $this->akuntansi->revenueByAccount($hariIni, $hariIni)->firstWhere('key', 'penyesuaian');

        $this->assertSame(300_000.0, $baris['total']);
        $this->assertSame('4-1000', $baris['account_code']);
    }

    #[Test]
    public function pembayaran_yang_dibatalkan_tidak_masuk_hitungan_akuntansi(): void
    {
        $pembayaran = $this->bayar(250_000, 'tunai');
        $hariIni = now()->toDateString();

        $this->assertCount(1, $this->akuntansi->paymentsByAccount($hariIni, $hariIni));

        $this->invoices->voidPayment($pembayaran, 'Salah input nominal', $this->kasir);

        $this->assertCount(
            0,
            $this->akuntansi->paymentsByAccount($hariIni, $hariIni),
            'View terbitan billing sudah menyaringnya, finance tidak perlu mengingat aturannya sendiri'
        );
    }

    #[Test]
    public function menutup_periode_membekukan_angkanya(): void
    {
        $this->bayar(400_000, 'tunai');

        $closing = $this->akuntansi->closePeriod((int) now()->year, (int) now()->month, $this->keuangan);

        $this->assertNotEmpty($closing->lines);
        $baris = $closing->lines->firstWhere('key', 'tunai');
        $this->assertSame(400_000.0, (float) $baris->total);

        // Uang baru masuk SETELAH periode ditutup.
        $this->bayar(100_000, 'tunai');

        $this->assertSame(
            400_000.0,
            (float) $closing->refresh()->lines->firstWhere('key', 'tunai')->total,
            'Buku yang sudah ditutup tidak boleh diam-diam berubah'
        );
    }

    #[Test]
    public function periode_yang_sama_tidak_bisa_ditutup_dua_kali(): void
    {
        $this->bayar(100_000, 'tunai');
        $this->akuntansi->closePeriod((int) now()->year, (int) now()->month, $this->keuangan);

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('sudah ditutup');

        $this->akuntansi->closePeriod((int) now()->year, (int) now()->month, $this->keuangan);
    }

    #[Test]
    public function periode_yang_belum_berjalan_tidak_bisa_ditutup(): void
    {
        $depan = now()->addMonths(2);

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('belum berjalan');

        $this->akuntansi->closePeriod((int) $depan->year, (int) $depan->month, $this->keuangan);
    }

    #[Test]
    public function periode_bisa_dibuka_kembali_lalu_ditutup_ulang(): void
    {
        $this->bayar(100_000, 'tunai');
        $tahun = (int) now()->year;
        $bulan = (int) now()->month;

        $closing = $this->akuntansi->closePeriod($tahun, $bulan, $this->keuangan);
        $this->akuntansi->reopenPeriod($closing, 'Ada koreksi tagihan yang terlambat masuk', $this->keuangan);

        $this->assertTrue($closing->refresh()->isReopened());
        $this->assertNull($this->akuntansi->activeClosing($tahun, $bulan));

        // Indeks unik parsial: yang sudah dibuka kembali tidak menghalangi penutupan baru.
        $baru = $this->akuntansi->closePeriod($tahun, $bulan, $this->keuangan);

        $this->assertNotSame($closing->id, $baru->id);
        $this->assertSame(2, PeriodClosing::query()->count());
    }

    #[Test]
    public function menutup_buku_digerbangi_terpisah_dari_melihat_laporan(): void
    {
        $manajemen = $this->pengguna('uji-manajemen-akun', 'manajemen');

        // Manajemen boleh melihat laporannya.
        $this->actingAs($manajemen)->get(route('akuntansi.index'))->assertOk();

        // Tapi tidak boleh menutup buku.
        $this->actingAs($manajemen)->post(route('akuntansi.tutup'), [
            'tahun' => now()->year, 'bulan' => now()->month,
        ])->assertForbidden();

        // Keuangan boleh keduanya.
        $this->actingAs($this->keuangan)->get(route('akuntansi.index'))->assertOk();
        $this->actingAs($this->keuangan)->post(route('akuntansi.tutup'), [
            'tahun' => now()->year, 'bulan' => now()->month,
        ])->assertRedirect()->assertSessionHas('sukses');
    }

    #[Test]
    public function kasir_tidak_bisa_membuka_layar_akuntansi(): void
    {
        $this->actingAs($this->kasir)->get(route('akuntansi.index'))->assertForbidden();
    }

    #[Test]
    public function pemetaan_bisa_disimpan_lewat_http_dan_menimpa_yang_lama(): void
    {
        $kas = Account::query()->where('type', Account::TYPE_KAS)->value('id');
        $pendapatan = Account::query()->where('type', Account::TYPE_PENDAPATAN)->value('id');

        $this->actingAs($this->keuangan)->post(route('akuntansi.pemetaan'), [
            'kind' => 'cara-bayar', 'key' => 'tunai', 'account_id' => $kas,
        ])->assertRedirect()->assertSessionHas('sukses');

        $this->actingAs($this->keuangan)->post(route('akuntansi.pemetaan'), [
            'kind' => 'cara-bayar', 'key' => 'tunai', 'account_id' => $pendapatan,
        ])->assertRedirect();

        $this->assertSame(1, AccountMapping::query()->where('key', 'tunai')->count(), 'Satu kunci satu pemetaan');
        $this->assertSame($pendapatan, AccountMapping::query()->where('key', 'tunai')->value('account_id'));
    }

    // ------------------------------------------------------------------ bantu

    private function bayar(float $nilai, string $metode)
    {
        $tagihan = $this->tagihan();
        $this->invoices->addAdjustment($tagihan, ManualAdjustment::KIND_TAMBAHAN, 'Biaya uji', $nilai, $this->kasir);

        return $this->invoices->pay($tagihan->refresh(), $nilai, $metode, $this->kasir);
    }

    private function tagihan()
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Akuntansi ' . $urut, 'sex' => 'L', 'birth_date' => '1990-01-01',
        ]);

        $registrasi = app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->firstOrFail()->id,
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
        );

        return $this->invoices->openInvoice($registrasi->id);
    }

    private function pengguna(string $username, string $peran): User
    {
        $user = User::query()->create([
            'username' => $username, 'name' => ucfirst($peran) . ' Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $peran)->firstOrFail());

        return $user;
    }
}
