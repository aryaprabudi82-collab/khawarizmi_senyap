<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Services\AccountingPeriodService;
use App\Modules\Finance\Services\FinanceReadiness;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\ReadinessCheck;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Kesiapan konteks finance — dilaporkan ke `siap:periksa`.
 *
 * MENGAPA INI DIUJI. Butir kesiapan yang salah jauh lebih berbahaya
 * daripada tidak ada butir sama sekali: ia melaporkan "beres" pada
 * keadaan yang belum beres, dan yang membacanya adalah orang yang
 * memutuskan apakah rumah sakit boleh mulai beroperasi.
 */
class FinanceReadinessTest extends TestCase
{
    use RefreshDatabase;

    private FinanceReadiness $kesiapan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->kesiapan = app(FinanceReadiness::class);
    }

    /**
     * Bagan akun contoh seeder HARUS dilaporkan sebagai penghalang.
     * Tanpa bagan akun sungguhan, seluruh pendapatan dan beban tidak punya
     * tempat jatuh di buku besar.
     */
    #[Test]
    public function bagan_akun_contoh_dilaporkan_sebagai_penghalang(): void
    {
        $butir = $this->butir('Bagan akun RSP UI');

        $this->assertSame(ReadinessCheck::MENGHALANGI, $butir['status']);
        $this->assertStringContainsString('contoh pengembangan', $butir['akibat']);
    }

    #[Test]
    public function bagan_akun_yang_sudah_disusun_dilaporkan_beres(): void
    {
        for ($i = 1; $i <= 11; $i++) {
            DB::table('finance.chart_of_accounts')->insert([
                'code' => '9-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'name' => 'Akun Uji '.$i,
                'type' => 'beban',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertSame(ReadinessCheck::BERES, $this->butir('Bagan akun RSP UI')['status']);
    }

    /**
     * Kalender bolong adalah PERINGATAN, bukan penghalang — sistem yang
     * sudah berjalan tidak boleh mati demi kalender yang belum diisi
     * siapa pun. Tapi akibatnya harus disebut terang: tutup buku tidak
     * bisa dijalankan untuk bulan itu.
     */
    #[Test]
    public function kalender_bolong_jadi_peringatan_bukan_penghalang(): void
    {
        $butir = $this->butir('Kalender periode akuntansi');

        $this->assertSame(ReadinessCheck::PERINGATAN, $butir['status']);
        $this->assertStringContainsString('TUTUP BUKU tidak bisa dijalankan', $butir['akibat']);
    }

    #[Test]
    public function kalender_lengkap_dilaporkan_beres(): void
    {
        app(AccountingPeriodService::class)->siapkanTahun((int) now()->year);

        $this->assertSame(ReadinessCheck::BERES, $this->butir('Kalender periode akuntansi')['status']);
    }

    /**
     * Akun tanpa klasifikasi PSAK tidak punya tempat di Laporan Posisi
     * Keuangan — saldonya benar di buku besar tapi hilang dari laporan
     * yang dikirim ke luar, dan laporannya tetap tampak seimbang.
     */
    #[Test]
    public function akun_tanpa_klasifikasi_psak_dilaporkan(): void
    {
        /*
         * Akunnya dibuat EKSPLISIT di sini. Percobaan pertama
         * mengandalkan akun bawaan seeder dan lulus di basis data
         * pengembangan yang kebetulan berisi — padahal ReferenceDataSeeder
         * TIDAK memuat ChartOfAccountsSeeder, jadi di basis data uji tidak
         * ada satu pun akun. Uji yang bergantung pada isi basis data yang
         * kebetulan ada akan lulus atau gagal tergantung siapa yang
         * menjalankannya.
         */
        $this->akun('1-0001', null);

        $butir = $this->butir('Klasifikasi akun PSAK');

        $this->assertSame(ReadinessCheck::PERINGATAN, $butir['status']);
        $this->assertStringContainsString('Laporan Posisi Keuangan', $butir['akibat']);
    }

    #[Test]
    public function akun_yang_sudah_diklasifikasi_dilaporkan_beres(): void
    {
        $this->akun('1-0002', 'aset-lancar');

        $this->assertSame(ReadinessCheck::BERES, $this->butir('Klasifikasi akun PSAK')['status']);
    }

    /**
     * Butir kesiapan harus menyebut AKIBATNYA, bukan mengulang judulnya.
     * Yang membacanya sedang memutuskan mana dikerjakan lebih dulu, dan
     * "belum diisi" tidak membantunya memutuskan apa pun.
     */
    #[Test]
    public function tiap_butir_menyebut_akibat_bukan_mengulang_judul(): void
    {
        foreach ($this->kesiapan->readinessItems() as $butir) {
            $this->assertGreaterThan(40, strlen($butir['akibat']),
                "Butir '{$butir['judul']}' terlalu pendek untuk menjelaskan akibatnya.");
        }
    }

    private function akun(string $kode, ?string $klasifikasi): void
    {
        DB::table('finance.chart_of_accounts')->insert([
            'code' => $kode,
            'name' => 'Akun '.$kode,
            'type' => 'kas',
            'klasifikasi' => $klasifikasi,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{judul: string, status: string, akibat: string} */
    private function butir(string $judul): array
    {
        foreach ($this->kesiapan->readinessItems() as $b) {
            if ($b['judul'] === $judul) {
                return $b;
            }
        }

        $this->fail("Butir kesiapan '{$judul}' tidak ditemukan.");
    }
}
