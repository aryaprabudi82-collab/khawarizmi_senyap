<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Services\FinanceException;
use App\Modules\Finance\Services\GaplessNumberAllocator;
use App\Modules\Finance\Services\IdempotencyGuard;
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
 * Tiga fondasi domain keuangan, dikerjakan lebih dulu karena tidak
 * bergantung pada satu pun pertanyaan terbuka.
 *
 * Ketiganya adalah temuan Discovery Tahap 0:
 *
 *  1. Jurnal balance hanya dijaga APLIKASI — tidak ada penahan di basis
 *     data. Satu jalur tulis baru yang lupa memeriksanya merusak seluruh
 *     neraca tanpa satu pun tanda.
 *  2. Idempotency NOL — nol kolom, nol tabel. Retry jaringan pada beban
 *     2.000 pasien/hari menghasilkan tagihan ganda.
 *  3. Penomoran sudah aman-konkurensi tapi BELUM gapless — nomor hilang
 *     saat transaksi rollback, dan untuk faktur pajak itu temuan audit.
 */
class FinancialFoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->petugas = User::query()->create([
            'username' => 'uji-fondasi-keuangan', 'name' => 'Petugas Keuangan',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    // ==================================================== 1. BALANCE JURNAL

    /**
     * INTI PENAHAN INI. Jurnal miring ditolak BASIS DATA, bukan aplikasi —
     * dibuktikan dengan menulis langsung lewat query builder, melewati
     * seluruh pemeriksaan PHP di LedgerService.
     */
    #[Test]
    public function basis_data_menolak_jurnal_tidak_seimbang(): void
    {
        [$a, $b] = $this->duaAkun();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('tidak seimbang');

        DB::transaction(function () use ($a, $b) {
            $id = $this->headerJurnal('sengaja miring');

            DB::table('finance.journal_lines')->insert([
                ['journal_entry_id' => $id, 'account_id' => $a, 'debit' => 100000, 'credit' => 0],
                ['journal_entry_id' => $id, 'account_id' => $b, 'debit' => 0, 'credit' => 75000],
            ]);

            $this->tembakConstraint();
        });
    }

    /**
     * Jurnal tanpa satu pun baris juga ditolak. Secara aritmetika ia
     * "balance" (0 = 0), tapi ia dokumen yang tidak menyatakan apa-apa —
     * biasanya sisa transaksi yang gagal di tengah.
     */
    #[Test]
    public function basis_data_menolak_jurnal_tanpa_baris(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('tidak punya satu pun baris');

        DB::transaction(function () {
            $this->headerJurnal('kosong');
            $this->tembakConstraint();
        });
    }

    #[Test]
    public function jurnal_seimbang_tetap_bisa_disimpan(): void
    {
        [$a, $b] = $this->duaAkun();

        DB::transaction(function () use ($a, $b) {
            $id = $this->headerJurnal('seimbang');

            DB::table('finance.journal_lines')->insert([
                ['journal_entry_id' => $id, 'account_id' => $a, 'debit' => 50000, 'credit' => 0],
                ['journal_entry_id' => $id, 'account_id' => $b, 'debit' => 0, 'credit' => 50000],
            ]);
        });

        $this->assertSame(1, DB::table('finance.journal_entries')->where('description', 'seimbang')->count());
    }

    /**
     * MENGHAPUS satu baris dari jurnal yang tadinya seimbang juga ditolak.
     * Tanpa ini, penahan hanya menjaga saat penulisan — dan koreksi data
     * yang menghapus sebaris jurnal akan merusaknya belakangan.
     */
    #[Test]
    public function menghapus_baris_yang_merusak_keseimbangan_ditolak(): void
    {
        [$a, $b] = $this->duaAkun();

        $id = null;
        DB::transaction(function () use ($a, $b, &$id) {
            $id = $this->headerJurnal('akan dirusak');
            DB::table('finance.journal_lines')->insert([
                ['journal_entry_id' => $id, 'account_id' => $a, 'debit' => 30000, 'credit' => 0],
                ['journal_entry_id' => $id, 'account_id' => $b, 'debit' => 0, 'credit' => 30000],
            ]);
        });

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('tidak seimbang');

        DB::transaction(function () use ($id) {
            DB::table('finance.journal_lines')->where('journal_entry_id', $id)->limit(1)->delete();
            $this->tembakConstraint();
        });
    }

    /** LedgerService yang sudah ada tetap bekerja dengan penahan baru ini. */
    #[Test]
    public function ledger_service_lama_tetap_berjalan(): void
    {
        $buku = app(LedgerService::class);
        [$a, $b] = $this->duaAkun();

        $jurnal = $buku->postManual(now()->toDateString(), 'Uji lewat service', [
            ['account_id' => $a, 'debit' => 25000, 'credit' => 0],
            ['account_id' => $b, 'debit' => 0, 'credit' => 25000],
        ], $this->petugas->id);

        $this->assertNotNull($jurnal->id);
    }

    // ====================================================== 2. IDEMPOTENCY

    /** Kiriman ulang dengan kunci & isi sama TIDAK menjalankan pekerjaan dua kali. */
    #[Test]
    public function kiriman_ulang_tidak_menjalankan_pekerjaan_dua_kali(): void
    {
        $guard = app(IdempotencyGuard::class);
        $jalan = 0;

        $isi = ['registrasi' => 1, 'item' => 'LAB-001'];
        $kerja = function () use (&$jalan) {
            $jalan++;

            return ['nomor' => 'INV-001'];
        };

        $pertama = $guard->jalankan('uji.charge', 'kunci-a', $isi, $kerja);
        $kedua = $guard->jalankan('uji.charge', 'kunci-a', $isi, $kerja);

        $this->assertSame(1, $jalan, 'Pekerjaan hanya boleh berjalan sekali');
        $this->assertFalse($pertama['pengulangan']);
        $this->assertTrue($kedua['pengulangan']);
        $this->assertSame($pertama['hasil'], $kedua['hasil'],
            'Kiriman ulang harus menerima JAWABAN YANG SAMA, bukan galat');
    }

    /**
     * Kunci sama dengan isi BERBEDA ditolak. Mengembalikan jawaban
     * transaksi pertama di sini berarti memberi tahu petugas bahwa
     * tagihan B tersimpan padahal yang tersimpan tagihan A.
     */
    #[Test]
    public function kunci_sama_dengan_isi_berbeda_ditolak(): void
    {
        $guard = app(IdempotencyGuard::class);

        $guard->jalankan('uji.charge', 'kunci-b', ['jumlah' => 100], fn () => ['ok' => true]);

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('isi yang BERBEDA');

        $guard->jalankan('uji.charge', 'kunci-b', ['jumlah' => 999], fn () => ['ok' => true]);
    }

    /**
     * Kunci kosong DITOLAK, bukan dilewatkan. Melewatkannya berarti
     * endpoint yang lupa mengirim kunci berjalan tanpa penahan sama
     * sekali, dan kelalaian itu tidak akan terlihat sampai ada tagihan
     * ganda.
     */
    #[Test]
    public function kunci_kosong_ditolak(): void
    {
        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('wajib membawa Idempotency-Key');

        app(IdempotencyGuard::class)->jalankan('uji.charge', null, [], fn () => true);
    }

    /** Kunci yang sama pada OPERASI berbeda tidak saling mengganggu. */
    #[Test]
    public function kunci_sama_pada_operasi_berbeda_tidak_bertabrakan(): void
    {
        $guard = app(IdempotencyGuard::class);

        $a = $guard->jalankan('uji.charge', 'kunci-c', ['x' => 1], fn () => ['dari' => 'charge']);
        $b = $guard->jalankan('uji.payment', 'kunci-c', ['x' => 1], fn () => ['dari' => 'payment']);

        $this->assertSame(['dari' => 'charge'], $a['hasil']);
        $this->assertSame(['dari' => 'payment'], $b['hasil']);
        $this->assertFalse($b['pengulangan']);
    }

    /**
     * Pekerjaan yang GAGAL ditandai gagal, dan kunci yang sama tidak bisa
     * dipakai lagi — supaya kegagalannya tetap terlacak alih-alih
     * tertimpa percobaan berikutnya.
     */
    #[Test]
    public function pekerjaan_gagal_ditandai_dan_kuncinya_tidak_dipakai_ulang(): void
    {
        $guard = app(IdempotencyGuard::class);

        try {
            $guard->jalankan('uji.charge', 'kunci-d', ['x' => 1], function () {
                throw new \RuntimeException('gagal disengaja');
            });
        } catch (\RuntimeException) {
            // diharapkan
        }

        $this->assertSame('gagal', DB::table('finance.idempotency_records')
            ->where('idempotency_key', 'kunci-d')->value('status'));

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('sebelumnya GAGAL');

        $guard->jalankan('uji.charge', 'kunci-d', ['x' => 1], fn () => true);
    }

    #[Test]
    public function catatan_kedaluwarsa_dibersihkan(): void
    {
        $guard = app(IdempotencyGuard::class);
        $guard->jalankan('uji.charge', 'kunci-e', ['x' => 1], fn () => true);

        DB::table('finance.idempotency_records')->update(['expires_at' => now()->subDay()]);

        $this->assertSame(1, $guard->bersihkanKedaluwarsa());
        $this->assertSame(0, DB::table('finance.idempotency_records')->count());
    }

    // ================================================= 3. PENOMORAN GAPLESS

    #[Test]
    public function nomor_terbit_berurutan_tanpa_lompatan(): void
    {
        $alok = app(GaplessNumberAllocator::class);

        $nomor = DB::transaction(fn () => [
            $alok->terbitkan(GaplessNumberAllocator::FAKTUR_PAJAK, 'FP'),
            $alok->terbitkan(GaplessNumberAllocator::FAKTUR_PAJAK, 'FP'),
            $alok->terbitkan(GaplessNumberAllocator::FAKTUR_PAJAK, 'FP'),
        ]);

        $this->assertSame('FP/'.now()->format('Y').'/000001', $nomor[0]);
        $this->assertSame('FP/'.now()->format('Y').'/000003', $nomor[2]);

        $hasil = $alok->periksaKeutuhan(GaplessNumberAllocator::FAKTUR_PAJAK, now()->format('Y'));
        $this->assertTrue($hasil['utuh']);
        $this->assertSame([], $hasil['hilang']);
    }

    /**
     * INTI PENOMORAN INI, dan yang membedakannya dari NumberAllocator
     * lama: transaksi yang ROLLBACK tidak meninggalkan lubang. Pada
     * penomoran lama, nomornya hilang dan barisannya berlubang.
     */
    #[Test]
    public function transaksi_yang_rollback_tidak_meninggalkan_lubang(): void
    {
        $alok = app(GaplessNumberAllocator::class);
        $tahun = now()->format('Y');

        DB::transaction(fn () => $alok->terbitkan(GaplessNumberAllocator::JURNAL, 'JV'));

        try {
            DB::transaction(function () use ($alok) {
                $alok->terbitkan(GaplessNumberAllocator::JURNAL, 'JV');
                throw new \RuntimeException('batal di tengah');
            });
        } catch (\RuntimeException) {
            // diharapkan
        }

        $berikutnya = DB::transaction(fn () => $alok->terbitkan(GaplessNumberAllocator::JURNAL, 'JV'));

        $this->assertSame("JV/{$tahun}/000002", $berikutnya,
            'Nomor yang batal harus kembali dipakai — itulah arti gapless');

        $this->assertTrue($alok->periksaKeutuhan(GaplessNumberAllocator::JURNAL, $tahun)['utuh']);
    }

    /**
     * Nomor yang dibatalkan TIDAK dipakai ulang, dan barisannya tetap
     * utuh. Dua dokumen dengan nomor sama jauh lebih berbahaya daripada
     * satu nomor yang batal berikut alasannya.
     */
    #[Test]
    public function nomor_dibatalkan_tetap_tercatat_dan_tidak_dipakai_ulang(): void
    {
        $alok = app(GaplessNumberAllocator::class);
        $tahun = now()->format('Y');

        $pertama = DB::transaction(fn () => $alok->terbitkan(GaplessNumberAllocator::KUITANSI, 'KW'));
        $alok->batalkan($pertama, 'Salah nama pasien');

        $kedua = DB::transaction(fn () => $alok->terbitkan(GaplessNumberAllocator::KUITANSI, 'KW'));

        $this->assertSame("KW/{$tahun}/000002", $kedua);
        $this->assertTrue($alok->periksaKeutuhan(GaplessNumberAllocator::KUITANSI, $tahun)['utuh'],
            'Nomor yang dibatalkan masih punya barisnya — barisan tetap utuh');

        $this->assertSame('dibatalkan',
            DB::table('finance.document_numbers')->where('formatted', $pertama)->value('status'));
    }

    #[Test]
    public function pembatalan_wajib_beralasan(): void
    {
        $alok = app(GaplessNumberAllocator::class);
        $nomor = DB::transaction(fn () => $alok->terbitkan(GaplessNumberAllocator::BUKTI_KAS, 'BK'));

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('wajib menyebutkan alasannya');

        $alok->batalkan($nomor, '   ');
    }

    /**
     * Penomoran AMAN dipanggil tanpa dibungkus transaksi sendiri.
     *
     * Versi pertama justru MENOLAK pemanggilan di luar transaksi, dengan
     * maksud memaksa pemanggil membungkusnya. Maksudnya benar, alatnya
     * salah: pemanggil yang lupa akan menerima galat alih-alih nomor, dan
     * pada jalur penerbitan faktur galat itu menghentikan pekerjaan yang
     * seharusnya bisa berjalan. Sekarang methodnya membungkus dirinya
     * sendiri — `DB::transaction()` bersarang memakai savepoint, jadi
     * tetap benar saat dipanggil dari dalam transaksi yang lebih besar.
     */
    #[Test]
    public function penomoran_aman_dipanggil_tanpa_transaksi_pembungkus(): void
    {
        $nomor = app(GaplessNumberAllocator::class)
            ->terbitkan(GaplessNumberAllocator::JURNAL, 'JV');

        $this->assertSame('JV/'.now()->format('Y').'/000001', $nomor);
    }

    // ------------------------------------------------------------- pembantu

    /**
     * Memaksa constraint trigger yang DITANGGUHKAN menembak sekarang.
     *
     * MENGAPA DIBUTUHKAN — dan ini batas pengujian, bukan cacat kode.
     * Trigger balance sengaja DEFERRABLE INITIALLY DEFERRED: ia menembak
     * saat COMMIT, karena di tengah transaksi jurnal yang baru punya satu
     * baris memang belum seimbang, dan itu keadaan yang sah.
     *
     * Tapi di bawah RefreshDatabase, SELURUH uji dibungkus satu transaksi
     * yang sengaja tidak pernah di-commit — supaya basis datanya bersih
     * lagi sesudahnya. Akibatnya trigger deferred TIDAK PERNAH menembak,
     * dan uji yang seharusnya membuktikan penahannya bekerja justru
     * lolos tanpa membuktikan apa pun.
     *
     * `SET CONSTRAINTS ALL IMMEDIATE` memaksanya dievaluasi di titik ini,
     * persis seperti yang akan terjadi saat COMMIT sungguhan.
     */
    private function tembakConstraint(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    /** @return array{0: int, 1: int} */
    private function duaAkun(): array
    {
        $buku = app(LedgerService::class);

        return [
            $buku->createAccount(['code' => '1-9001', 'name' => 'Kas Uji', 'type' => 'kas'])->id,
            $buku->createAccount(['code' => '4-9001', 'name' => 'Pendapatan Uji', 'type' => 'pendapatan'])->id,
        ];
    }

    private function headerJurnal(string $keterangan): int
    {
        return DB::table('finance.journal_entries')->insertGetId([
            'entry_number' => 'UJI-'.uniqid(),
            'entry_date' => now(),
            'description' => $keterangan,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
