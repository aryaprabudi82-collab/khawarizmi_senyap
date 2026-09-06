<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\PeriodClosing;
use App\Modules\Finance\Services\FinanceException;
use App\Modules\Finance\Services\LedgerService;
use App\Modules\Finance\Database\Seeders\ChartOfAccountsSeeder;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Bagan akun, jurnal manual & buku besar (domain K item E).
 *
 * Item paling berkonsekuensi di domain K, karena inilah yang membuat
 * seluruh pemetaan akun di item A/B/C bisa diisi sama sekali. Yang paling
 * perlu dikunci: jurnal wajib seimbang, periode tertutup tidak bisa
 * disisipi, dan saldo disajikan menurut arah normal akunnya.
 */
class LedgerTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $buku;
    private User $keuangan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class, ChartOfAccountsSeeder::class]);

        $this->buku = app(LedgerService::class);

        $this->keuangan = User::query()->create([
            'username' => 'uji-keuangan-buku', 'name' => 'Petugas Keuangan', 'password' => 'password', 'is_active' => true,
        ]);
        $this->keuangan->roles()->attach(Role::query()->where('code', 'petugas-keuangan')->firstOrFail());
    }

    // ----------------------------------------------------------- bagan akun

    #[Test]
    public function akun_baru_bisa_dibuat_termasuk_jenis_aset_dan_modal(): void
    {
        $aset = $this->buku->createAccount(['code' => '1-3000', 'name' => 'Peralatan Medis', 'type' => 'aset']);
        $modal = $this->buku->createAccount(['code' => '3-1000', 'name' => 'Modal Disetor', 'type' => 'modal']);

        $this->assertSame('aset', $aset->type);
        $this->assertSame('modal', $modal->type);
        $this->assertTrue($aset->isDebitNormal());
        $this->assertFalse($modal->isDebitNormal());
    }

    #[Test]
    public function kode_akun_ganda_ditolak(): void
    {
        $this->buku->createAccount(['code' => '1-3000', 'name' => 'Peralatan', 'type' => 'aset']);

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('sudah ada');

        $this->buku->createAccount(['code' => '1-3000', 'name' => 'Peralatan Lain', 'type' => 'aset']);
    }

    /** Akun dinonaktifkan, bukan dihapus — buku besar lama harus tetap terbaca. */
    #[Test]
    public function akun_dinonaktifkan_bukan_dihapus(): void
    {
        $akun = $this->buku->createAccount(['code' => '1-3000', 'name' => 'Peralatan', 'type' => 'aset']);

        $this->buku->deactivateAccount($akun);

        $this->assertFalse($akun->refresh()->is_active);
        $this->assertDatabaseHas('finance.chart_of_accounts', ['code' => '1-3000']);
    }

    #[Test]
    public function akun_nonaktif_tidak_bisa_dijurnal(): void
    {
        $kas = $this->akun('1-1000');
        $mati = $this->buku->createAccount(['code' => '1-3000', 'name' => 'Peralatan', 'type' => 'aset']);
        $this->buku->deactivateAccount($mati);

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('nonaktif');

        $this->buku->postManual(now()->toDateString(), 'Uji', [
            ['account_id' => $mati->id, 'debit' => 100_000],
            ['account_id' => $kas->id, 'credit' => 100_000],
        ]);
    }

    // -------------------------------------------------------- jurnal manual

    /**
     * Inti item ini: jurnal yang tidak seimbang merusak seluruh neraca
     * yang dibangun di atasnya, dan selisihnya baru ketahuan
     * berbulan-bulan kemudian saat ada yang menutup buku.
     */
    #[Test]
    public function jurnal_tidak_seimbang_ditolak(): void
    {
        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('tidak seimbang');

        $this->buku->postManual(now()->toDateString(), 'Jurnal miring', [
            ['account_id' => $this->akun('1-1000')->id, 'debit' => 100_000],
            ['account_id' => $this->akun('4-1000')->id, 'credit' => 90_000],
        ]);
    }

    #[Test]
    public function jurnal_seimbang_tersimpan_tanpa_rujukan_palsu(): void
    {
        $jurnal = $this->jurnal(100_000);

        $this->assertNull($jurnal->reference_type, 'Jurnal manual tidak mengarang rujukan');
        $this->assertNull($jurnal->reference_id);
        $this->assertStringStartsWith('JU', $jurnal->entry_number);

        $baris = $this->buku->journalLines($jurnal);
        $this->assertCount(2, $baris);
        $this->assertSame(100_000.0, (float) $baris->sum('debit'));
        $this->assertSame(100_000.0, (float) $baris->sum('credit'));
    }

    #[Test]
    public function satu_baris_tidak_boleh_berisi_debit_dan_kredit_sekaligus(): void
    {
        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('debit atau kredit, tidak keduanya');

        $this->buku->postManual(now()->toDateString(), 'Uji', [
            ['account_id' => $this->akun('1-1000')->id, 'debit' => 100_000, 'credit' => 100_000],
            ['account_id' => $this->akun('4-1000')->id, 'credit' => 100_000],
        ]);
    }

    #[Test]
    public function jurnal_satu_baris_ditolak(): void
    {
        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('dua baris');

        $this->buku->postManual(now()->toDateString(), 'Uji', [
            ['account_id' => $this->akun('1-1000')->id, 'debit' => 100_000],
        ]);
    }

    /**
     * Penutupan periode membekukan angka yang sudah dilaporkan; menambah
     * jurnal ke dalamnya membuat laporan yang sudah dikirim tidak lagi
     * cocok dengan sistemnya sendiri.
     */
    #[Test]
    public function jurnal_tidak_bisa_masuk_periode_yang_sudah_ditutup(): void
    {
        PeriodClosing::query()->create([
            'period_year' => (int) now()->year,
            'period_month' => (int) now()->month,
            'closed_at' => now(),
            'closed_by' => $this->keuangan->id,
        ]);

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('sudah ditutup');

        $this->jurnal(100_000);
    }

    #[Test]
    public function periode_yang_dibuka_kembali_bisa_dijurnal_lagi(): void
    {
        $tutup = PeriodClosing::query()->create([
            'period_year' => (int) now()->year,
            'period_month' => (int) now()->month,
            'closed_at' => now(),
            'closed_by' => $this->keuangan->id,
        ]);

        $tutup->update(['reopened_at' => now(), 'reopen_reason' => 'koreksi']);

        $this->assertNotNull($this->jurnal(100_000)->id);
    }

    // ------------------------------------------------------------ buku besar

    /**
     * Akun kredit-normal (pendapatan, utang, modal) BERTAMBAH oleh kredit.
     * Menyajikan saldonya bertanda tanpa memperhatikan arah normal akan
     * membuat setiap akun pendapatan tampil negatif.
     */
    #[Test]
    public function saldo_disajikan_menurut_arah_normal_akunnya(): void
    {
        $this->jurnal(500_000);

        $kas = $this->buku->ledger($this->akun('1-1000'), $this->awalTahun(), $this->hariIni());
        $pendapatan = $this->buku->ledger($this->akun('4-1000'), $this->awalTahun(), $this->hariIni());

        $this->assertSame('debit', $kas['arah_normal']);
        $this->assertSame(500_000.0, $kas['saldo_akhir'], 'Kas bertambah oleh debit');

        $this->assertSame('kredit', $pendapatan['arah_normal']);
        $this->assertSame(500_000.0, $pendapatan['saldo_akhir'],
            'Pendapatan bertambah oleh kredit — bukan minus 500.000');
    }

    #[Test]
    public function saldo_awal_tahun_ikut_dihitung(): void
    {
        $kas = $this->akun('1-1000');

        $this->buku->setOpeningBalance($kas, (int) now()->year, 2_000_000, 0);
        $this->jurnal(500_000);

        $bb = $this->buku->ledger($kas, $this->awalTahun(), $this->hariIni());

        $this->assertSame(2_000_000.0, $bb['saldo_awal_tahun']);
        $this->assertSame(2_500_000.0, $bb['saldo_akhir'], 'Saldo awal + mutasi');
    }

    /** Saldo awal idempoten: menghitung ulang mengganti, bukan menambah. */
    #[Test]
    public function saldo_awal_yang_dihitung_ulang_mengganti_bukan_menambah(): void
    {
        $kas = $this->akun('1-1000');

        $this->buku->setOpeningBalance($kas, (int) now()->year, 2_000_000, 0);
        $this->buku->setOpeningBalance($kas, (int) now()->year, 3_000_000, 0);

        $bb = $this->buku->ledger($kas, $this->awalTahun(), $this->hariIni());

        $this->assertSame(3_000_000.0, $bb['saldo_awal_tahun'], 'Bukan 5.000.000');
        $this->assertCount(1, $this->buku->openingBalances((int) now()->year));
    }

    #[Test]
    public function saldo_awal_tidak_boleh_diisi_kedua_sisi(): void
    {
        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('satu sisi saja');

        $this->buku->setOpeningBalance($this->akun('1-1000'), (int) now()->year, 100_000, 100_000);
    }

    /** Mutasi sebelum rentang harus masuk saldo pembuka, bukan hilang. */
    #[Test]
    public function mutasi_sebelum_rentang_masuk_saldo_pembuka(): void
    {
        $this->jurnal(300_000, now()->subDays(10)->toDateString());
        $this->jurnal(200_000);

        $bb = $this->buku->ledger($this->akun('1-1000'), now()->subDays(2)->toDateString(), $this->hariIni());

        $this->assertSame(300_000.0, $bb['saldo_pembuka'], 'Jurnal 10 hari lalu tidak hilang');
        $this->assertSame(500_000.0, $bb['saldo_akhir']);
        $this->assertCount(1, $bb['mutasi'], 'Hanya mutasi di dalam rentang yang dirinci');
    }

    // ---------------------------------------------------------- neraca saldo

    #[Test]
    public function neraca_saldo_seimbang_saat_seluruh_jurnal_seimbang(): void
    {
        $this->jurnal(500_000);
        $this->jurnal(300_000);

        $neraca = $this->buku->trialBalance($this->awalTahun(), $this->hariIni());

        $this->assertSame(800_000.0, $neraca->total_debit);
        $this->assertSame(800_000.0, $neraca->total_kredit);
        $this->assertSame(0.0, $neraca->selisih);
        $this->assertTrue($neraca->seimbang);
    }

    #[Test]
    public function jurnal_harian_memisahkan_yang_manual_dari_yang_otomatis(): void
    {
        $this->jurnal(500_000);

        $semua = $this->buku->dailyJournal($this->awalTahun(), $this->hariIni());
        $manual = $this->buku->dailyJournal($this->awalTahun(), $this->hariIni(), 'manual');

        $this->assertCount(1, $semua);
        $this->assertCount(1, $manual);
        $this->assertSame('manual', $semua->first()->reference_type, 'Rujukan kosong ditampilkan sebagai "manual"');
    }

    #[Test]
    public function saldo_akun_per_bulan_dikelompokkan_menurut_bulannya(): void
    {
        $this->jurnal(500_000);

        $perBulan = $this->buku->monthlyBalances((int) now()->year);

        $this->assertNotEmpty($perBulan);
        $this->assertSame(now()->format('Y-m'), $perBulan->first()->bulan);
    }


    // ----------------------------------------------------------------- layar

    #[Test]
    public function layar_buku_hanya_untuk_petugas_keuangan(): void
    {
        $this->actingAs($this->keuangan)->get(route('buku.index'))->assertOk();

        $dokter = User::query()->create([
            'username' => 'uji-dokter-buku', 'name' => 'Dokter', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)->get(route('buku.index'))->assertForbidden();
    }

    #[Test]
    public function akun_bisa_ditambah_lewat_http(): void
    {
        $this->actingAs($this->keuangan)
            ->post(route('buku.akun.simpan'), ['code' => '2-2000', 'name' => 'Hutang Usaha', 'type' => 'utang'])
            ->assertRedirect();

        $this->assertDatabaseHas('finance.chart_of_accounts', ['code' => '2-2000', 'type' => 'utang']);
    }

    /** Baris jurnal yang dikosongkan pengguna bukan kesalahan — dibuang sebelum diperiksa seimbang. */
    #[Test]
    public function baris_jurnal_kosong_diabaikan_lewat_http(): void
    {
        $this->actingAs($this->keuangan)
            ->post(route('buku.jurnal'), [
                'entry_date' => $this->hariIni(),
                'description' => 'Jurnal lewat formulir',
                'lines' => [
                    ['account_id' => $this->akun('1-1000')->id, 'debit' => 250_000],
                    ['account_id' => $this->akun('4-1000')->id, 'credit' => 250_000],
                    ['account_id' => $this->akun('1-1000')->id],
                    ['account_id' => $this->akun('1-1000')->id],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('finance.journal_lines', 2);
    }

    #[Test]
    public function jurnal_tidak_seimbang_lewat_http_ditolak_dengan_pesan(): void
    {
        $this->actingAs($this->keuangan)
            ->post(route('buku.jurnal'), [
                'entry_date' => $this->hariIni(),
                'description' => 'Jurnal miring',
                'lines' => [
                    ['account_id' => $this->akun('1-1000')->id, 'debit' => 250_000],
                    ['account_id' => $this->akun('4-1000')->id, 'credit' => 200_000],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('lines');

        $this->assertDatabaseCount('finance.journal_entries', 0);
    }

    #[Test]
    public function layar_menyatakan_perannya_sebagai_dasar_pemetaan_akun(): void
    {
        $this->actingAs($this->keuangan)
            ->get(route('buku.index'))
            ->assertOk()
            ->assertSee('membuat pemetaan di layar kas, hutang, dan piutang bisa diisi', false);
    }

    // ------------------------------------------------------------------ bantu

    private function hariIni(): string
    {
        return now()->toDateString();
    }

    private function awalTahun(): string
    {
        return now()->startOfYear()->toDateString();
    }

    private function akun(string $code): Account
    {
        return Account::query()->where('code', $code)->firstOrFail();
    }

    private function jurnal(float $nilai, ?string $tanggal = null)
    {
        return $this->buku->postManual(
            $tanggal ?? $this->hariIni(),
            'Jurnal uji ' . $nilai,
            [
                ['account_id' => $this->akun('1-1000')->id, 'debit' => $nilai],
                ['account_id' => $this->akun('4-1000')->id, 'credit' => $nilai],
            ],
            $this->keuangan->id,
        );
    }
}
