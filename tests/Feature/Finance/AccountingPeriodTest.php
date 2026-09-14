<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Services\AccountingPeriodService;
use App\Modules\Finance\Services\FinanceException;
use App\Modules\Finance\Services\LedgerService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Kalender periode akuntansi — Modul A butir 1.9.
 *
 * YANG PALING PERLU DIKUNCI: jurnal tidak bisa masuk ke periode yang sudah
 * ditutup, dan penahannya ada di BASIS DATA. Jurnal yang menyelinap ke
 * periode tertutup adalah kerusakan yang paling sulit ketahuan — laporan
 * yang sudah dikirim ke luar berubah diam-diam, tanpa satu pun galat.
 *
 * Aplikasi yang memeriksanya saja tidak cukup: seeder, perintah konsol,
 * dan perbaikan data lewat tinker semuanya melewatinya.
 */
class AccountingPeriodTest extends TestCase
{
    use RefreshDatabase;

    private AccountingPeriodService $periode;

    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->periode = app(AccountingPeriodService::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-periode', 'name' => 'Petugas Keuangan',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    // ------------------------------------------------------------- kalender

    #[Test]
    public function menyiapkan_tahun_membuat_dua_belas_periode(): void
    {
        $this->assertSame(12, $this->periode->siapkanTahun(2026));

        $jan = $this->periode->periode(2026, 1);
        $this->assertSame('2026-01-01', $jan->starts_on);
        $this->assertSame('2026-01-31', $jan->ends_on);
        $this->assertSame(AccountingPeriodService::TERBUKA, $jan->status);
    }

    /** Februari kabisat harus berakhir tanggal 29, bukan 28. */
    #[Test]
    public function akhir_bulan_dihitung_bukan_ditebak(): void
    {
        $this->periode->siapkanTahun(2028);

        $this->assertSame('2028-02-29', $this->periode->periode(2028, 2)->ends_on);
    }

    #[Test]
    public function menyiapkan_tahun_dua_kali_tidak_menggandakan(): void
    {
        $this->periode->siapkanTahun(2026);

        $this->assertSame(0, $this->periode->siapkanTahun(2026));
        $this->assertSame(12, DB::table('finance.accounting_periods')->where('period_year', 2026)->count());
    }

    // ------------------------------------------------------ perpindahan status

    #[Test]
    public function perpindahan_wajar_diterima(): void
    {
        $this->periode->siapkanTahun(2026);

        $this->ubah(1, AccountingPeriodService::SOFT_CLOSE, 'Merapikan angka Januari');
        $this->assertSame('soft-close', $this->periode->periode(2026, 1)->status);

        $this->ubah(1, AccountingPeriodService::TERTUTUP, 'Laporan Januari selesai');
        $this->assertSame('tertutup', $this->periode->periode(2026, 1)->status);

        $this->ubah(1, AccountingPeriodService::TERKUNCI, 'Laporan dikirim ke MWA');
        $this->assertSame('terkunci', $this->periode->periode(2026, 1)->status);
    }

    /**
     * Periode tidak boleh melompat dari terbuka langsung ke terkunci.
     * Mengunci berarti menyatakan laporannya sudah dikirim, dan laporan
     * tidak bisa dikirim dari periode yang belum pernah ditutup.
     */
    #[Test]
    public function tidak_bisa_melompat_dari_terbuka_ke_terkunci(): void
    {
        $this->periode->siapkanTahun(2026);

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('tidak bisa langsung dikunci');

        $this->ubah(1, AccountingPeriodService::TERKUNCI, 'Buru-buru');
    }

    /**
     * SATU-SATUNYA KEADAAN TANPA JALAN MUNDUR. Yang sudah keluar rumah
     * sakit tidak boleh berubah diam-diam.
     */
    #[Test]
    public function terkunci_tidak_bisa_dibuka_lewat_aplikasi(): void
    {
        $this->periode->siapkanTahun(2026);
        $this->ubah(1, AccountingPeriodService::TERTUTUP, 'Selesai');
        $this->ubah(1, AccountingPeriodService::TERKUNCI, 'Dikirim ke auditor');

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('tidak bisa dibuka lewat aplikasi');

        $this->ubah(1, AccountingPeriodService::TERBUKA, 'Ada koreksi');
    }

    /** Periode tertutup MASIH bisa dibuka — yang tidak bisa hanya terkunci. */
    #[Test]
    public function tertutup_masih_bisa_dibuka_kembali(): void
    {
        $this->periode->siapkanTahun(2026);
        $this->ubah(1, AccountingPeriodService::TERTUTUP, 'Selesai');

        $this->ubah(1, AccountingPeriodService::TERBUKA, 'Ditemukan koreksi material');

        $this->assertSame('terbuka', $this->periode->periode(2026, 1)->status);
    }

    #[Test]
    public function perpindahan_selain_terbuka_wajib_beralasan(): void
    {
        $this->periode->siapkanTahun(2026);

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('wajib menyebutkan alasannya');

        $this->periode->ubahStatus(2026, 1, AccountingPeriodService::TERTUTUP, $this->petugas, '   ');
    }

    #[Test]
    public function periode_belum_terdaftar_ditolak(): void
    {
        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('belum terdaftar');

        $this->ubah(1, AccountingPeriodService::TERTUTUP, 'Apa saja');
    }

    // ------------------------------------------- penahan jurnal di basis data

    /**
     * INTI BERKAS INI. Jurnal ke periode tertutup ditolak BASIS DATA —
     * dibuktikan dengan menulis langsung lewat query builder, melewati
     * seluruh pemeriksaan PHP.
     */
    #[Test]
    public function basis_data_menolak_jurnal_ke_periode_tertutup(): void
    {
        $this->periode->siapkanTahun(2026);
        $this->ubah(3, AccountingPeriodService::TERTUTUP, 'Laporan Maret selesai');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('sudah ditutup');

        DB::table('finance.journal_entries')->insert([
            'entry_number' => 'UJI-'.uniqid(),
            'entry_date' => '2026-03-15',
            'description' => 'Menyelinap ke periode tertutup',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function basis_data_menolak_jurnal_ke_periode_terkunci(): void
    {
        $this->periode->siapkanTahun(2026);
        $this->ubah(3, AccountingPeriodService::TERTUTUP, 'Selesai');
        $this->ubah(3, AccountingPeriodService::TERKUNCI, 'Dikirim');

        $this->expectException(QueryException::class);

        DB::table('finance.journal_entries')->insert([
            'entry_number' => 'UJI-'.uniqid(),
            'entry_date' => '2026-03-15',
            'description' => 'Menyelinap',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * SOFT-CLOSE MELOLOSKAN JURNAL KOREKSI — itu memang gunanya. Yang
     * dibedakan sumbernya: jurnal operasional (punya reference_type)
     * ditolak, jurnal manual diterima.
     */
    #[Test]
    public function soft_close_menolak_jurnal_operasional_tapi_meloloskan_koreksi(): void
    {
        $this->periode->siapkanTahun(2026);
        $this->ubah(4, AccountingPeriodService::SOFT_CLOSE, 'Merapikan April');

        // Jurnal koreksi manual: DITERIMA.
        $buku = app(LedgerService::class);
        [$a, $b] = $this->duaAkun();

        $jurnal = $buku->postManual('2026-04-20', 'Koreksi manual April', [
            ['account_id' => $a, 'debit' => 10000, 'credit' => 0],
            ['account_id' => $b, 'debit' => 0, 'credit' => 10000],
        ], $this->petugas->id);

        $this->assertNotNull($jurnal->id);

        // Jurnal bersumber transaksi operasional: DITOLAK.
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('soft-close');

        DB::table('finance.journal_entries')->insert([
            'entry_number' => 'UJI-'.uniqid(),
            'entry_date' => '2026-04-20',
            'description' => 'Dari billing',
            'reference_type' => 'invoice',
            'reference_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Periode yang BELUM TERDAFTAR diloloskan, dan itu disengaja: memaksa
     * seluruh periode terdaftar lebih dulu akan mematikan sistem yang
     * sudah berjalan hari ini demi kalender yang belum diisi siapa pun.
     * Kelengkapannya dilaporkan, bukan ditegakkan trigger.
     */
    #[Test]
    public function periode_belum_terdaftar_diloloskan_bukan_menghentikan_sistem(): void
    {
        $buku = app(LedgerService::class);
        [$a, $b] = $this->duaAkun();

        $jurnal = $buku->postManual('2029-07-01', 'Periode belum didaftarkan', [
            ['account_id' => $a, 'debit' => 5000, 'credit' => 0],
            ['account_id' => $b, 'debit' => 0, 'credit' => 5000],
        ], $this->petugas->id);

        $this->assertNotNull($jurnal->id);
        $this->assertTrue($this->periode->bolehDijurnal('2029-07-01'));
    }

    #[Test]
    public function bulan_yang_belum_terdaftar_bisa_dilaporkan(): void
    {
        $this->periode->siapkanTahun(2026);

        $hilang = $this->periode->bulanBelumTerdaftar(2026, 2027);

        $this->assertCount(12, $hilang, 'Seluruh 2027 belum terdaftar');
        $this->assertContains('2027-01', $hilang->all());
        $this->assertNotContains('2026-01', $hilang->all());
    }

    // ------------------------------------------------------------- pembantu

    private function ubah(int $bulan, string $status, string $alasan): void
    {
        $this->periode->ubahStatus(2026, $bulan, $status, $this->petugas, $alasan);
    }

    /** @return array{0: int, 1: int} */
    private function duaAkun(): array
    {
        $buku = app(LedgerService::class);

        return [
            $buku->createAccount(['code' => '1-7001', 'name' => 'Kas Uji', 'type' => 'kas'])->id,
            $buku->createAccount(['code' => '4-7001', 'name' => 'Pendapatan Uji', 'type' => 'pendapatan'])->id,
        ];
    }
}
