<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Services\FinancialStatementService;
use App\Modules\Finance\Services\LedgerService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Neraca & Laba-Rugi — dua laporan keuangan pokok.
 *
 * CACAT YANG DITEMUKAN SAAT MEMBANGUNNYA, dan yang paling perlu dikunci:
 * ketujuh jenis akun BERCAMPUR DUA TINGKATAN. `aset`, `utang`, `modal`,
 * `pendapatan`, `beban` adalah golongan akuntansi yang sah — tapi `kas`
 * dan `piutang` sesungguhnya SUB-GOLONGAN ASET yang terlanjur
 * disejajarkan dengan induknya.
 *
 * Neraca yang dikelompokkan menurut `type` akan kehilangan seluruh kas
 * dan piutang rumah sakit — dua pos terbesar pada neraca rumah sakit mana
 * pun. Neracanya tetap tersusun rapi dan tetap seimbang, hanya salah.
 */
class FinancialStatementTest extends TestCase
{
    use RefreshDatabase;

    private FinancialStatementService $laporan;

    private LedgerService $buku;

    private User $keuangan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->laporan = app(FinancialStatementService::class);
        $this->buku = app(LedgerService::class);

        $this->keuangan = User::query()->create([
            'username' => 'uji-keuangan-laporan', 'name' => 'Petugas Keuangan',
            'password' => 'password', 'is_active' => true,
        ]);
        $this->keuangan->roles()->attach(Role::query()->where('code', 'petugas-keuangan')->firstOrFail());
    }

    /**
     * INTI BERKAS INI. Akun berjenis `kas` dan `piutang` HARUS masuk
     * kelompok aset — kalau tidak, neraca rumah sakit kehilangan dua pos
     * terbesarnya tanpa satu pun tanda bahwa ada yang hilang.
     */
    #[Test]
    public function kas_dan_piutang_masuk_kelompok_aset(): void
    {
        $this->assertSame(Account::GOL_ASET, Account::golonganDari(Account::TYPE_KAS));
        $this->assertSame(Account::GOL_ASET, Account::golonganDari(Account::TYPE_PIUTANG));
        $this->assertSame(Account::GOL_KEWAJIBAN, Account::golonganDari(Account::TYPE_UTANG));

        $kas = $this->akun('1-1000', 'Kas', Account::TYPE_KAS);
        $modal = $this->akun('3-1000', 'Modal', Account::TYPE_MODAL);

        $this->jurnal('2026-01-05', [[$kas, 5_000_000, 0], [$modal, 0, 5_000_000]]);

        $neraca = $this->laporan->neraca('2026-01-31');

        $this->assertSame(5_000_000.0, $neraca->total[Account::GOL_ASET],
            'Akun berjenis kas harus terhitung sebagai aset');
        $this->assertTrue($neraca->seimbang);
    }

    /**
     * NERACA ADALAH KEADAAN, BUKAN ALIRAN. Saldonya dihitung sejak awal
     * pembukuan sampai tanggalnya — bukan dari satu rentang. Menghitungnya
     * per rentang menghasilkan "aset yang bertambah bulan ini", angka yang
     * masuk akal, jauh lebih kecil, dan salah.
     */
    #[Test]
    public function neraca_menghitung_sejak_awal_bukan_per_rentang(): void
    {
        $kas = $this->akun('1-1000', 'Kas', Account::TYPE_KAS);
        $modal = $this->akun('3-1000', 'Modal', Account::TYPE_MODAL);

        $this->jurnal('2026-01-10', [[$kas, 1_000_000, 0], [$modal, 0, 1_000_000]]);
        $this->jurnal('2026-02-10', [[$kas, 500_000, 0], [$modal, 0, 500_000]]);

        // Neraca akhir Februari memuat KEDUA jurnal, bukan hanya Februari.
        $this->assertSame(1_500_000.0, $this->laporan->neraca('2026-02-28')->total[Account::GOL_ASET]);

        // Neraca akhir Januari hanya memuat yang sampai Januari.
        $this->assertSame(1_000_000.0, $this->laporan->neraca('2026-01-31')->total[Account::GOL_ASET]);
    }

    /** Laba-rugi kebalikannya: HANYA rentang itu. */
    #[Test]
    public function laba_rugi_hanya_menghitung_rentangnya(): void
    {
        $kas = $this->akun('1-1000', 'Kas', Account::TYPE_KAS);
        $pendapatan = $this->akun('4-1000', 'Pendapatan Layanan', Account::TYPE_PENDAPATAN);

        $this->jurnal('2026-01-10', [[$kas, 3_000_000, 0], [$pendapatan, 0, 3_000_000]]);
        $this->jurnal('2026-02-10', [[$kas, 2_000_000, 0], [$pendapatan, 0, 2_000_000]]);

        $februari = $this->laporan->labaRugi('2026-02-01', '2026-02-28');

        $this->assertSame(2_000_000.0, $februari->total_pendapatan,
            'Pendapatan Januari tidak boleh ikut terjumlah ke Februari');
        $this->assertSame(2_000_000.0, $februari->surplus);
    }

    /**
     * PENDAPATAN DISAJIKAN POSITIF meski saldonya kredit. Tanpa pembalikan
     * arah normal, setiap akun pendapatan tampil negatif dan pembacanya
     * menyimpulkan sistemnya rusak.
     */
    #[Test]
    public function pendapatan_disajikan_positif_bukan_negatif(): void
    {
        $kas = $this->akun('1-1000', 'Kas', Account::TYPE_KAS);
        $pendapatan = $this->akun('4-1000', 'Pendapatan', Account::TYPE_PENDAPATAN);

        $this->jurnal('2026-03-01', [[$kas, 750_000, 0], [$pendapatan, 0, 750_000]]);

        $lr = $this->laporan->labaRugi('2026-03-01', '2026-03-31');

        $this->assertSame(750_000.0, $lr->pendapatan->first()->saldo);
        $this->assertSame(750_000.0, $lr->surplus);
    }

    /**
     * SURPLUS BERJALAN MASUK NERACA SEBAGAI BAGIAN MODAL. Tanpa itu neraca
     * TIDAK AKAN PERNAH seimbang selama ada pendapatan yang belum ditutup
     * ke modal — dan neraca tidak seimbang dibaca sebagai kerusakan
     * sistem, padahal yang kurang cuma satu baris.
     */
    #[Test]
    public function surplus_berjalan_menyeimbangkan_neraca(): void
    {
        $kas = $this->akun('1-1000', 'Kas', Account::TYPE_KAS);
        $pendapatan = $this->akun('4-1000', 'Pendapatan', Account::TYPE_PENDAPATAN);
        $beban = $this->akun('5-1000', 'Beban Operasional', Account::TYPE_BEBAN);

        $this->jurnal('2026-04-01', [[$kas, 10_000_000, 0], [$pendapatan, 0, 10_000_000]]);
        $this->jurnal('2026-04-05', [[$beban, 4_000_000, 0], [$kas, 0, 4_000_000]]);

        $neraca = $this->laporan->neraca('2026-04-30');

        $this->assertSame(6_000_000.0, $neraca->total_aset);
        $this->assertSame(6_000_000.0, $neraca->surplus_berjalan);
        $this->assertTrue($neraca->seimbang, 'Selisih: '.$neraca->selisih);
    }

    /** Beban dan pendapatan TIDAK boleh nongol di neraca. */
    #[Test]
    public function neraca_tidak_memuat_pendapatan_maupun_beban(): void
    {
        $kas = $this->akun('1-1000', 'Kas', Account::TYPE_KAS);
        $pendapatan = $this->akun('4-1000', 'Pendapatan', Account::TYPE_PENDAPATAN);

        $this->jurnal('2026-05-01', [[$kas, 1_000_000, 0], [$pendapatan, 0, 1_000_000]]);

        $kode = $this->laporan->neraca('2026-05-31')->kelompok
            ->flatten(1)->pluck('code')->all();

        $this->assertContains('1-1000', $kode);
        $this->assertNotContains('4-1000', $kode,
            'Pendapatan adalah aliran sepanjang periode, bukan keadaan pada satu tanggal');
    }

    /**
     * TANPA JURNAL, LAPORANNYA MENYATAKAN BELUM ADA — bukan menyajikan
     * nol. Nol berarti "harta rumah sakit nol"; belum ada jurnal berarti
     * "belum ada yang masuk buku besar". Dua pernyataan yang sangat
     * berbeda, dan yang pertama akan menakuti orang tanpa alasan.
     */
    #[Test]
    public function tanpa_jurnal_dinyatakan_belum_ada_bukan_nol(): void
    {
        $this->assertTrue($this->laporan->neraca(now()->toDateString())->belum_dijurnal);
        $this->assertTrue($this->laporan->labaRugi('2026-01-01', '2026-12-31')->belum_dijurnal);
    }

    // ------------------------------------------------------------ layarnya

    #[Test]
    public function pusat_keuangan_terbuka_untuk_yang_berhak(): void
    {
        $this->actingAs($this->keuangan)->get(route('keuangan.index'))
            ->assertOk()
            ->assertSee('Pusat Keuangan')
            ->assertSee('Neraca');
    }

    #[Test]
    public function pusat_keuangan_tertutup_untuk_yang_tidak_berhak(): void
    {
        $perawat = User::query()->create([
            'username' => 'uji-perawat-keuangan', 'name' => 'Perawat',
            'password' => 'password', 'is_active' => true,
        ]);
        $perawat->roles()->attach(Role::query()->where('code', 'perawat')->firstOrFail());

        $this->actingAs($perawat)->get(route('keuangan.index'))->assertForbidden();
    }

    /**
     * LAYARNYA MENYEBUT SENDIRI BAHWA ANGKANYA BELUM BISA DIPAKAI selama
     * bagan akun masih berisi contoh seeder. Laporan keuangan yang
     * tersaji rapi akan dipercaya apa adanya.
     */
    #[Test]
    public function layar_menyatakan_belum_bisa_dipakai_menutup_buku(): void
    {
        $this->actingAs($this->keuangan)->get(route('keuangan.index'))
            ->assertSee('belum bisa dipakai menutup buku')
            ->assertSee('Bagan akun RSP UI');
    }

    // ------------------------------------------------------------- pembantu

    private function akun(string $kode, string $nama, string $jenis): Account
    {
        return $this->buku->createAccount([
            'code' => $kode,
            'name' => $nama,
            'type' => $jenis,
        ]);
    }

    /** @param  list<array{0:Account,1:float,2:float}>  $baris */
    private function jurnal(string $tanggal, array $baris): void
    {
        $this->buku->postManual(
            $tanggal,
            'Uji laporan keuangan',
            array_map(fn (array $b) => [
                'account_id' => $b[0]->id,
                'debit' => $b[1],
                'credit' => $b[2],
            ], $baris),
            $this->keuangan->id,
        );
    }
}
