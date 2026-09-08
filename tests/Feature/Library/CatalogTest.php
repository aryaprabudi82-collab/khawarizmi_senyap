<?php

namespace Tests\Feature\Library;

use App\Modules\Library\Models\Author;
use App\Modules\Library\Models\Collection as LibraryCollection;
use App\Modules\Library\Services\CatalogService;
use App\Modules\Library\Services\LibraryException;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Katalog perpustakaan (domain Q item A).
 *
 * Yang dikunci:
 *
 * 1. EBOOK ADALAH MEDIUM, BUKAN TABEL KEDUA — satu pencarian menemukan
 *    cetak dan ebook sekaligus.
 * 2. EBOOK WAJIB BERBERKAS, CETAK TIDAK BOLEH.
 * 3. ISBN UNIK BILA DIISI, boleh kosong bila memang tidak ada.
 * 4. PENGARANG JAMAK DAN URUT SITASI.
 */
class CatalogTest extends TestCase
{
    use RefreshDatabase;

    private CatalogService $katalog;

    private User $pustakawan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->katalog = app(CatalogService::class);

        $this->pustakawan = User::query()->create([
            'username' => 'uji-pustakawan', 'name' => 'Pustakawan Uji',
            'password' => 'password', 'is_active' => true,
        ]);
        $this->pustakawan->roles()->attach(Role::query()->where('code', 'pustakawan')->firstOrFail());
    }

    // ============================================ ebook sebagai medium

    #[Test]
    public function satu_pencarian_menemukan_koleksi_cetak_dan_ebook_sekaligus(): void
    {
        $this->daftar('BK-001', 'Anatomi Klinis Dasar');
        $this->daftar('EB-001', 'Anatomi Klinis Digital', [
            'medium' => 'ebook', 'file_path' => 'ebook/anatomi-digital.pdf',
        ]);

        $hasil = $this->katalog->search('anatomi');

        /*
         * Khanza memisahkan buku dan ebook jadi dua tabel. Pencarian yang
         * menggabungkan keduanya lalu lupa salah satunya TETAP menghasilkan
         * daftar yang terlihat wajar — hanya saja tanpa separuh koleksi, dan
         * tidak ada yang tahu.
         */
        $this->assertCount(2, $hasil);
        $this->assertSame(['cetak', 'ebook'], $hasil->pluck('medium')->sort()->values()->all());
    }

    #[Test]
    public function pencarian_bisa_disaring_menurut_medium(): void
    {
        $this->daftar('BK-002', 'Farmakologi Dasar');
        $this->daftar('EB-002', 'Farmakologi Digital', [
            'medium' => 'ebook', 'file_path' => 'ebook/farmakologi.pdf',
        ]);

        // "Cari Koleksi Ebook" pada Khanza adalah layar tersendiri tanpa
        // access flag; di sini ia penyaring atas data yang sama.
        $this->assertCount(1, $this->katalog->search('farmakologi', 'ebook'));
        $this->assertCount(1, $this->katalog->search('farmakologi', 'cetak'));
    }

    #[Test]
    public function ebook_tanpa_berkas_ditolak(): void
    {
        /*
         * Entri ebook tanpa berkas ditemukan pemustaka di hasil pencarian,
         * dikira tersedia, lalu tidak menghasilkan apa-apa — lebih buruk
         * daripada tidak mengatalogkannya sama sekali.
         */
        $this->expectException(LibraryException::class);
        $this->expectExceptionMessageMatches('/tidak menghasilkan apa-apa/');

        $this->daftar('EB-003', 'Ebook Tanpa Berkas', ['medium' => 'ebook']);
    }

    #[Test]
    public function koleksi_cetak_tidak_boleh_punya_berkas(): void
    {
        $this->expectException(LibraryException::class);
        $this->expectExceptionMessageMatches('/daftarkan sebagai ebook/');

        $this->daftar('BK-003', 'Buku Cetak', ['file_path' => 'ebook/salah.pdf']);
    }

    #[Test]
    public function basis_data_menolak_ebook_tanpa_berkas_meski_service_dilewati(): void
    {
        $this->expectException(QueryException::class);

        DB::table('library.collections')->insert([
            'code' => 'EB-LANGSUNG', 'title' => 'Lewat pintu belakang',
            'medium' => 'ebook', 'file_path' => null, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ==================================================== ISBN

    #[Test]
    public function isbn_yang_sama_tidak_bisa_didaftarkan_dua_kali(): void
    {
        $this->daftar('BK-004', 'Harrison Principles', ['isbn' => '978-1-260-45463-9']);

        /*
         * ISBN menandai satu EDISI secara tunggal. Dua entri dengan ISBN
         * sama berarti buku yang sama dikatalogkan dua kali: jumlah judul
         * yang dilaporkan jadi lebih besar daripada kenyataannya, dan
         * pemustaka menemukan dua entri yang masing-masing menunjukkan
         * sebagian eksemplarnya.
         */
        $this->expectException(QueryException::class);

        $this->daftar('BK-005', 'Harrison Principles (salah ketik ulang)', ['isbn' => '978-1-260-45463-9']);
    }

    #[Test]
    public function koleksi_tanpa_isbn_boleh_lebih_dari_satu(): void
    {
        // Skripsi, laporan penelitian, dan terbitan internal memang tidak
        // punya ISBN — memaksakannya akan menolak koleksi yang sah.
        $this->daftar('SK-001', 'Skripsi Keperawatan A');
        $this->daftar('SK-002', 'Skripsi Keperawatan B');

        $this->assertSame(2, LibraryCollection::query()->whereNull('isbn')->count());
    }

    // =============================================== pengarang jamak

    #[Test]
    public function koleksi_bisa_punya_banyak_pengarang_dan_urutannya_disimpan(): void
    {
        $a = $this->pengarang('P-001', 'Anthony Fauci', 'Fauci, A.S.');
        $b = $this->pengarang('P-002', 'Dennis Kasper', 'Kasper, D.L.');
        $c = $this->pengarang('P-003', 'Stephen Hauser', 'Hauser, S.L.');

        // Sengaja tidak urut abjad: yang disimpan urutan SITASI.
        $koleksi = $this->daftar('BK-006', 'Harrison Internal Medicine', [], [$c->id, $a->id, $b->id]);

        $this->assertSame(
            'Hauser, S.L.; Fauci, A.S.; Kasper, D.L.',
            $koleksi->penulisTerurut()
        );
    }

    #[Test]
    public function pencarian_menemukan_koleksi_lewat_nama_pengarang_kedua(): void
    {
        $satu = $this->pengarang('P-004', 'Anthony Fauci');
        $dua = $this->pengarang('P-005', 'Dennis Kasper');

        $this->daftar('BK-007', 'Buku Teks Penyakit Dalam', [], [$satu->id, $dua->id]);

        /*
         * Inilah alasan pengarang tidak boleh cuma satu kolom: buku teks
         * kedokteran hampir selalu ditulis banyak orang, dan pemustaka yang
         * mencari nama pengarang kedua akan menyimpulkan bukunya tidak ada —
         * bukan bahwa katalognya tidak lengkap.
         */
        $this->assertCount(1, $this->katalog->search('kasper'));
    }

    #[Test]
    public function pengarang_yang_sama_tidak_bisa_dicantumkan_dua_kali(): void
    {
        $p = $this->pengarang('P-006', 'Anthony Fauci');

        $this->expectException(LibraryException::class);
        $this->expectExceptionMessageMatches('/dua kali/');

        $this->daftar('BK-008', 'Buku Ganda', [], [$p->id, $p->id]);
    }

    #[Test]
    public function pengarang_tidak_dikenal_ditolak(): void
    {
        $this->expectException(LibraryException::class);
        $this->expectExceptionMessageMatches('/tidak dikenal/');

        $this->daftar('BK-009', 'Buku Hantu', [], [999999]);
    }

    // ================================================== validasi lain

    #[Test]
    public function tahun_terbit_yang_mustahil_ditolak(): void
    {
        // Salah ketik yang lolos akan muncul di grafik terbitan per tahun
        // sebagai lonjakan di tahun yang belum terjadi.
        $this->expectException(QueryException::class);

        $this->daftar('BK-010', 'Buku Masa Depan', ['publication_year' => 9999]);
    }

    #[Test]
    public function medium_di_luar_daftar_ditolak(): void
    {
        $this->expectException(LibraryException::class);
        $this->expectExceptionMessageMatches('/tidak dikenal/');

        $this->daftar('BK-011', 'Buku Audio', ['medium' => 'audio']);
    }

    // ==================================================== kewenangan

    #[Test]
    public function layar_perpustakaan_untuk_pustakawan_bukan_petugas_tu(): void
    {
        $this->actingAs($this->pustakawan)->get(route('library.index'))->assertOk();
        $this->actingAs($this->pustakawan)->get(route('library.master.index'))->assertOk();

        /*
         * Peran baru, bukan dilekatkan ke petugas-tu: menggabungkannya akan
         * memberi petugas surat-menyurat akses ke riwayat pinjam seluruh
         * pegawai, dan daftar bacaan seseorang adalah hal yang tidak perlu
         * dibaca orang yang tidak mengelolanya.
         */
        $tu = User::query()->create([
            'username' => 'uji-tu-pustaka', 'name' => 'Petugas TU',
            'password' => 'password', 'is_active' => true,
        ]);
        $tu->roles()->attach(Role::query()->where('code', 'petugas-tu')->firstOrFail());

        $this->actingAs($tu)->get(route('library.index'))->assertForbidden();
    }

    // -------------------------------------------------------- fixture

    private function daftar(string $kode, string $judul, array $tambahan = [], array $pengarang = []): LibraryCollection
    {
        return $this->katalog->register($tambahan + [
            'code' => $kode,
            'title' => $judul,
        ], $pengarang);
    }

    private function pengarang(string $kode, string $nama, ?string $sitasi = null): Author
    {
        return Author::query()->create([
            'code' => $kode, 'name' => $nama, 'citation_name' => $sitasi, 'is_active' => true,
        ]);
    }
}
