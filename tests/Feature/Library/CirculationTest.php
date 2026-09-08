<?php

namespace Tests\Feature\Library;

use App\Modules\Library\Models\Collection as LibraryCollection;
use App\Modules\Library\Models\Fine;
use App\Modules\Library\Models\Item;
use App\Modules\Library\Models\Member;
use App\Modules\Library\Services\CirculationService;
use App\Modules\Library\Services\LibraryException;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Sirkulasi perpustakaan (domain Q item B).
 *
 * Yang dikunci — dua-duanya memperbaiki satu kolom Khanza yang menyimpan
 * dua hal berbeda:
 *
 * 1. KONDISI FISIK TERPISAH DARI STATUS SIRKULASI. `status_buku` Khanza
 *    mencampur (Ada, Rusak, Hilang) dengan (Dipinjam); di sini "sedang
 *    dipinjam" DIHITUNG dan tidak pernah disimpan.
 * 2. JATUH TEMPO TERPISAH DARI TANGGAL KEMBALI. `tgl_kembali` Khanza satu
 *    kolom untuk dua tanggal, padahal dendanya selisih keduanya.
 * 3. ATURAN PINJAM DIBEKUKAN saat meminjam.
 * 4. PEMBEBASAN DENDA WAJIB BERALASAN, pembayaran tidak.
 */
class CirculationTest extends TestCase
{
    use RefreshDatabase;

    private CirculationService $sirkulasi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->sirkulasi = app(CirculationService::class);

        $this->sirkulasi->setPolicy(['max_items' => 2, 'loan_days' => 7, 'daily_fine' => 1000]);
    }

    // ================================ kondisi vs status sirkulasi

    #[Test]
    public function buku_yang_dipinjam_lalu_kembali_rusak_tercatat_dua_duanya(): void
    {
        $eksemplar = $this->eksemplar('INV-001');
        $anggota = $this->anggota('AGT-001');

        $pinjam = $this->sirkulasi->borrow($anggota, $eksemplar);

        // Sedang dipinjam TAPI kondisinya masih baik — dua fakta terpisah.
        $this->assertTrue($eksemplar->refresh()->sedangDipinjam());
        $this->assertSame(Item::KONDISI_BAIK, $eksemplar->condition);

        $this->sirkulasi->returnItem($pinjam, Item::KONDISI_RUSAK);

        /*
         * Enum `status_buku` Khanza memaksa petugas memilih antara 'Rusak'
         * dan 'Dipinjam'; apa pun pilihannya ada satu fakta yang hilang.
         */
        $this->assertFalse($eksemplar->refresh()->sedangDipinjam());
        $this->assertSame(Item::KONDISI_RUSAK, $eksemplar->condition);
        $this->assertSame(Item::KONDISI_RUSAK, $pinjam->refresh()->returned_condition);
    }

    #[Test]
    public function sedang_dipinjam_dihitung_bukan_disimpan(): void
    {
        $eksemplar = $this->eksemplar('INV-002');

        // Tidak ada kolom apa pun pada eksemplar yang menyimpan "dipinjam" —
        // kalau ada, ia jadi sumber kedua bagi fakta yang sudah dipegang
        // tabel peminjaman, dan begitu satu transaksi gagal di tengah,
        // keduanya berbeda tanpa cara menentukan mana yang benar.
        $kolom = DB::select(
            "SELECT column_name FROM information_schema.columns
              WHERE table_schema='library' AND table_name='items'"
        );
        $nama = array_column($kolom, 'column_name');

        $this->assertNotContains('status', $nama);
        $this->assertNotContains('is_borrowed', $nama);
        $this->assertContains('condition', $nama);

        $this->assertFalse($eksemplar->sedangDipinjam());
    }

    #[Test]
    public function satu_eksemplar_tidak_bisa_dipinjam_dua_orang(): void
    {
        $eksemplar = $this->eksemplar('INV-003');

        $this->sirkulasi->borrow($this->anggota('AGT-002'), $eksemplar);

        $this->expectException(LibraryException::class);
        $this->expectExceptionMessageMatches('/sedang dipinjam/');

        $this->sirkulasi->borrow($this->anggota('AGT-003'), $eksemplar->refresh());
    }

    #[Test]
    public function basis_data_menolak_dua_pinjaman_terbuka_atas_eksemplar_yang_sama(): void
    {
        $eksemplar = $this->eksemplar('INV-004');
        $satu = $this->anggota('AGT-004');
        $dua = $this->anggota('AGT-005');

        $this->sirkulasi->borrow($satu, $eksemplar);

        /*
         * Tanpa indeks unik parsial ini, dua orang bisa tercatat memegang
         * buku fisik yang sama — dan yang kedua akan ditagih atas buku yang
         * tidak pernah ia terima.
         */
        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('library.loans')->insert([
            'loan_number' => 'PJM-LANGSUNG', 'member_id' => $dua->id, 'item_id' => $eksemplar->id,
            'borrowed_at' => now(), 'due_date' => now()->addDays(7)->toDateString(),
            'policy_daily_fine' => 1000, 'policy_loan_days' => 7,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    #[Test]
    public function eksemplar_rusak_tidak_bisa_dipinjamkan(): void
    {
        $eksemplar = $this->eksemplar('INV-005');
        $eksemplar->update(['condition' => Item::KONDISI_RUSAK]);

        $this->expectException(LibraryException::class);
        $this->expectExceptionMessageMatches('/berkondisi rusak/');

        $this->sirkulasi->borrow($this->anggota('AGT-006'), $eksemplar->refresh());
    }

    // ============================== jatuh tempo vs tanggal kembali

    #[Test]
    public function jatuh_tempo_dan_tanggal_kembali_adalah_dua_kolom_berbeda(): void
    {
        $pinjam = $this->sirkulasi->borrow($this->anggota('AGT-007'), $this->eksemplar('INV-006'));

        $this->assertSame(now()->addDays(7)->toDateString(), $pinjam->due_date->toDateString());
        $this->assertNull($pinjam->returned_at);

        $this->travel(3)->days();
        $this->sirkulasi->returnItem($pinjam);
        $this->travelBack();

        // Satu kolom `tgl_kembali` hanya bisa menjawab salah satunya.
        $pinjam->refresh();
        $this->assertNotNull($pinjam->due_date);
        $this->assertNotNull($pinjam->returned_at);
    }

    #[Test]
    public function keterlambatan_berhenti_bertambah_setelah_buku_kembali(): void
    {
        $pinjam = $this->sirkulasi->borrow($this->anggota('AGT-008'), $this->eksemplar('INV-007'));

        $this->travel(10)->days();

        // Belum kembali: 10 hari lewat jatuh tempo 7 hari = terlambat 3 hari,
        // dan angkanya memang masih bertambah karena bukunya masih di luar.
        $this->assertSame(3, $pinjam->refresh()->hariTerlambat());

        $this->sirkulasi->returnItem($pinjam->refresh());

        $this->travel(30)->days();

        /*
         * Sudah kembali: angkanya berhenti di 3. Dengan satu kolom tanggal
         * seperti Khanza, keterlambatan hanya bisa dihitung terhadap HARI
         * INI — dan buku yang dikembalikan terlambat tiga hari lalu akan
         * terus bertambah dendanya selama transaksinya tidak ditutup.
         */
        $this->assertSame(3, $pinjam->refresh()->hariTerlambat());

        $this->travelBack();
    }

    #[Test]
    public function buku_yang_kembali_tepat_waktu_tidak_terlambat(): void
    {
        $pinjam = $this->sirkulasi->borrow($this->anggota('AGT-009'), $this->eksemplar('INV-008'));

        $this->travel(7)->days();
        $this->sirkulasi->returnItem($pinjam->refresh());
        $this->travelBack();

        $this->assertSame(0, $pinjam->refresh()->hariTerlambat());
        $this->assertSame(0, $pinjam->fines()->count());
    }

    // ============================================ aturan dibekukan

    #[Test]
    public function mengubah_lama_pinjam_tidak_menggeser_jatuh_tempo_pinjaman_berjalan(): void
    {
        $pinjam = $this->sirkulasi->borrow($this->anggota('AGT-010'), $this->eksemplar('INV-009'));
        $jatuhTempoAwal = $pinjam->due_date->toDateString();

        $this->sirkulasi->setPolicy(['max_items' => 5, 'loan_days' => 30, 'daily_fine' => 5000]);

        /*
         * Kalau jatuh tempo dihitung ulang dari pengaturan yang berlaku hari
         * ini, mengubah lama pinjam dari 7 jadi 30 hari akan membuat
         * buku-buku yang kemarin terlambat mendadak jadi tepat waktu, tanpa
         * ada yang menyentuhnya.
         */
        $this->assertSame($jatuhTempoAwal, $pinjam->refresh()->due_date->toDateString());
        $this->assertSame(7, $pinjam->policy_loan_days);
        $this->assertEquals(1000, $pinjam->policy_daily_fine);
    }

    #[Test]
    public function denda_memakai_tarif_yang_berlaku_saat_meminjam(): void
    {
        $pinjam = $this->sirkulasi->borrow($this->anggota('AGT-011'), $this->eksemplar('INV-010'));

        // Tarif dinaikkan SETELAH bukunya dipinjam.
        $this->sirkulasi->setPolicy(['max_items' => 2, 'loan_days' => 7, 'daily_fine' => 9000]);

        $this->travel(9)->days();
        $this->sirkulasi->returnItem($pinjam->refresh());
        $this->travelBack();

        $denda = $pinjam->refresh()->fines()->first();

        // 2 hari terlambat x tarif SAAT MEMINJAM (1.000), bukan tarif baru.
        $this->assertSame(2, $denda->days_late);
        $this->assertEquals(2000, $denda->amount);
    }

    #[Test]
    public function meminjam_tanpa_pengaturan_ditolak(): void
    {
        DB::table('library.loan_policies')->update(['is_active' => false]);

        $this->expectException(LibraryException::class);
        $this->expectExceptionMessageMatches('/dibekukan ke pinjamannya/');

        $this->sirkulasi->borrow($this->anggota('AGT-012'), $this->eksemplar('INV-011'));
    }

    // ================================================= keanggotaan

    #[Test]
    public function anggota_yang_masa_berlakunya_habis_tidak_bisa_meminjam(): void
    {
        $anggota = $this->anggota('AGT-013');
        $anggota->update(['expires_at' => now()->subDay()->toDateString()]);

        $this->expectException(LibraryException::class);
        $this->expectExceptionMessageMatches('/Masa berlaku keanggotaan/');

        $this->sirkulasi->borrow($anggota->refresh(), $this->eksemplar('INV-012'));
    }

    #[Test]
    public function anggota_tidak_bisa_melebihi_batas_eksemplar(): void
    {
        $anggota = $this->anggota('AGT-014');

        $this->sirkulasi->borrow($anggota, $this->eksemplar('INV-013'));
        $this->sirkulasi->borrow($anggota->refresh(), $this->eksemplar('INV-014'));

        $this->expectException(LibraryException::class);
        $this->expectExceptionMessageMatches('/batasnya 2/');

        $this->sirkulasi->borrow($anggota->refresh(), $this->eksemplar('INV-015'));
    }

    #[Test]
    public function batas_dihitung_dari_yang_masih_dipegang_bukan_seluruh_riwayat(): void
    {
        $anggota = $this->anggota('AGT-015');

        $satu = $this->sirkulasi->borrow($anggota, $this->eksemplar('INV-016'));
        $this->sirkulasi->borrow($anggota->refresh(), $this->eksemplar('INV-017'));

        $this->sirkulasi->returnItem($satu->refresh());

        // Buku yang sudah dikembalikan tidak lagi "dipegang"; menghitung
        // seluruh riwayat akan memblokir anggota lama secara permanen.
        $ketiga = $this->sirkulasi->borrow($anggota->refresh(), $this->eksemplar('INV-018'));

        $this->assertNotNull($ketiga->id);
    }

    // ======================================================= denda

    #[Test]
    public function denda_keterlambatan_terbit_otomatis_saat_pengembalian_terlambat(): void
    {
        $pinjam = $this->sirkulasi->borrow($this->anggota('AGT-016'), $this->eksemplar('INV-019'));

        $this->travel(12)->days();
        $this->sirkulasi->returnItem($pinjam->refresh());
        $this->travelBack();

        $denda = $pinjam->refresh()->fines()->first();

        $this->assertSame(Fine::JENIS_KETERLAMBATAN, $denda->kind);
        $this->assertSame(5, $denda->days_late);
        $this->assertEquals(5000, $denda->amount);
        $this->assertTrue($denda->tertunggak());
    }

    #[Test]
    public function pembebasan_denda_wajib_beralasan(): void
    {
        $denda = $this->dendaKeterlambatan('AGT-017', 'INV-020');

        /*
         * Denda yang dibayar meninggalkan bukti pada uangnya sendiri; denda
         * yang dibebaskan tidak meninggalkan apa pun selain catatan ini —
         * dan pembebasan tanpa jejak adalah bentuk penyalahgunaan yang
         * paling mudah dilakukan orang dalam.
         */
        $this->expectException(LibraryException::class);
        $this->expectExceptionMessageMatches('/menyebutkan alasannya/');

        $this->sirkulasi->waive($denda, '   ');
    }

    #[Test]
    public function pembayaran_denda_tidak_wajib_berketerangan(): void
    {
        $denda = $this->dendaKeterlambatan('AGT-018', 'INV-021');

        $hasil = $this->sirkulasi->pay($denda, 3000.0);

        $this->assertNotNull($hasil->paid_at);
        $this->assertFalse($hasil->tertunggak());
    }

    #[Test]
    public function denda_tidak_bisa_dibayar_dua_kali(): void
    {
        $denda = $this->sirkulasi->pay($this->dendaKeterlambatan('AGT-019', 'INV-022'), 3000.0);

        $this->expectException(LibraryException::class);
        $this->expectExceptionMessageMatches('/sudah dibayar/');

        $this->sirkulasi->waive($denda, 'Berubah pikiran');
    }

    #[Test]
    public function basis_data_menolak_denda_yang_sekaligus_dibayar_dan_dibebaskan(): void
    {
        $denda = $this->dendaKeterlambatan('AGT-020', 'INV-023');

        // Kalau boleh, jumlah penerimaan denda dan jumlah pembebasan akan
        // sama-sama memuatnya, dan keduanya jadi lebih besar dari kenyataan.
        $this->expectException(QueryException::class);

        DB::table('library.fines')->where('id', $denda->id)->update([
            'paid_at' => now(), 'paid_amount' => 1000,
            'waived_at' => now(), 'waived_reason' => 'Dua-duanya',
        ]);
    }

    #[Test]
    public function basis_data_menolak_denda_bukan_keterlambatan_yang_punya_hari_terlambat(): void
    {
        $anggota = $this->anggota('AGT-021');

        // "Denda kerusakan 3 hari" bisa tersimpan kalau tidak ditegakkan, dan
        // tidak ada yang bisa membacanya sebagai apa pun.
        $this->expectException(QueryException::class);

        DB::table('library.fines')->insert([
            'fine_number' => 'DND-SALAH', 'member_id' => $anggota->id,
            'kind' => 'kerusakan', 'days_late' => 3, 'amount' => 50000,
            'charged_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    #[Test]
    public function daftar_jenis_denda_sengaja_lahir_kosong(): void
    {
        /*
         * Besaran denda kerusakan dan kehilangan adalah diskresi RSP UI —
         * sama seperti alasan menolak anjuran medis dan pola klasifikasi
         * arsip pada domain P. Denda keterlambatan tidak menunggu daftar
         * ini: besarannya keluar dari tarif harian yang dibekukan pada
         * pinjamannya.
         */
        $this->assertSame(0, DB::table('library.fine_types')->count());
    }

    #[Test]
    public function tunggakan_denda_anggota_dihitung_dari_yang_belum_selesai(): void
    {
        $anggota = $this->anggota('AGT-022');
        $denda = $this->dendaKeterlambatan(null, 'INV-024', $anggota);

        $this->assertEqualsWithDelta((float) $denda->amount, $anggota->refresh()->dendaTertunggak(), 0.01);

        $this->sirkulasi->pay($denda, (float) $denda->amount);

        $this->assertEqualsWithDelta(0.0, $anggota->refresh()->dendaTertunggak(), 0.01);
    }

    // =================================================== tunggakan

    #[Test]
    public function pinjaman_lewat_tempo_terdaftar_dari_yang_paling_lama(): void
    {
        $lama = $this->sirkulasi->borrow($this->anggota('AGT-023'), $this->eksemplar('INV-025'));

        $this->travel(10)->days();

        $baru = $this->sirkulasi->borrow($this->anggota('AGT-024'), $this->eksemplar('INV-026'));

        // Lama pinjam 7 hari: pinjaman kedua jatuh tempo pada hari ke-17,
        // jadi harus dilewati dulu — bukan hari ke-15 seperti draf pertama
        // uji ini, yang membuatnya belum terlambat dan uji ini gagal karena
        // harapannya yang keliru, bukan kodenya.
        $this->travel(12)->days();

        $tertunggak = $this->sirkulasi->overdue();

        // Yang paling lama menunggu di atas — daftar terbaru justru
        // menyembunyikan buku yang paling lama tidak kembali.
        $this->assertCount(2, $tertunggak);
        $this->assertSame($lama->id, $tertunggak->first()->id);
        $this->assertSame($baru->id, $tertunggak->last()->id);

        $this->travelBack();
    }

    #[Test]
    public function ebook_tidak_dipinjamkan_sebagai_eksemplar_fisik(): void
    {
        $ebook = LibraryCollection::query()->create([
            'code' => 'EB-SIRK', 'title' => 'Ebook Sirkulasi', 'medium' => 'ebook',
            'file_path' => 'ebook/sirkulasi.pdf', 'is_active' => true,
        ]);

        $eksemplar = Item::query()->create([
            'inventory_number' => 'INV-EBOOK', 'collection_id' => $ebook->id,
            'acquisition' => 'beli', 'condition' => Item::KONDISI_BAIK,
        ]);

        $this->expectException(LibraryException::class);
        $this->expectExceptionMessageMatches('/tidak dipinjamkan sebagai eksemplar fisik/');

        $this->sirkulasi->borrow($this->anggota('AGT-025'), $eksemplar);
    }

    // ==================================================== kewenangan

    #[Test]
    public function seluruh_layar_perpustakaan_terbuka_untuk_pustakawan(): void
    {
        $pustakawan = User::query()->create([
            'username' => 'uji-pustakawan-sirkulasi', 'name' => 'Pustakawan Sirkulasi',
            'password' => 'password', 'is_active' => true,
        ]);
        $pustakawan->roles()->attach(Role::query()->where('code', 'pustakawan')->firstOrFail());

        foreach ([
            'library.index', 'library.master.index', 'library.eksemplar.index',
            'library.anggota.index', 'library.sirkulasi.index', 'library.pengaturan.index',
        ] as $rute) {
            $this->actingAs($pustakawan)->get(route($rute))->assertOk();
        }

        /*
         * Register perpustakaan memuat siapa membaca apa. Daftar bacaan
         * seseorang adalah hal yang tidak perlu dibaca orang yang tidak
         * mengelolanya — karena itu peran tersendiri, bukan dilekatkan ke
         * petugas tata usaha.
         */
        $tu = User::query()->create([
            'username' => 'uji-tu-sirkulasi', 'name' => 'Petugas TU',
            'password' => 'password', 'is_active' => true,
        ]);
        $tu->roles()->attach(Role::query()->where('code', 'petugas-tu')->firstOrFail());

        $this->actingAs($tu)->get(route('library.sirkulasi.index'))->assertForbidden();
        $this->actingAs($tu)->get(route('library.anggota.index'))->assertForbidden();
    }

    // -------------------------------------------------------- fixture

    private function eksemplar(string $nomor): Item
    {
        static $urut = 0;
        $urut++;

        $koleksi = LibraryCollection::query()->create([
            'code' => 'BK-SIRK-'.$urut,
            'title' => 'Buku Sirkulasi '.$urut,
            'medium' => 'cetak',
            'is_active' => true,
        ]);

        return Item::query()->create([
            'inventory_number' => $nomor,
            'collection_id' => $koleksi->id,
            'acquisition' => 'beli',
            'condition' => Item::KONDISI_BAIK,
        ]);
    }

    private function anggota(string $nomor): Member
    {
        return Member::query()->create([
            'member_number' => $nomor,
            'name' => 'Anggota '.$nomor,
            'member_type' => 'pegawai',
            'joined_at' => now()->subMonth()->toDateString(),
            'is_active' => true,
        ]);
    }

    private function dendaKeterlambatan(?string $nomorAnggota, string $nomorEksemplar, ?Member $anggota = null): Fine
    {
        $anggota ??= $this->anggota((string) $nomorAnggota);

        $pinjam = $this->sirkulasi->borrow($anggota, $this->eksemplar($nomorEksemplar));

        $this->travel(10)->days();
        $this->sirkulasi->returnItem($pinjam->refresh());
        $this->travelBack();

        return $pinjam->refresh()->fines()->firstOrFail();
    }
}
