<?php

namespace Tests\Feature\Correspondence;

use App\Modules\Correspondence\Models\IncomingLetter;
use App\Modules\Correspondence\Models\LetterLocation;
use App\Modules\Correspondence\Services\CorrespondenceException;
use App\Modules\Correspondence\Services\LetterMasterService;
use App\Modules\Correspondence\Services\LetterService;
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
 * Arsip & penomoran surat (domain P item D).
 *
 * Yang dikunci:
 *
 * 1. SIFAT DAN DERAJAT ADALAH DUA SUMBU — surat rahasia yang juga
 *    mendesak harus bisa dicatat sebagai keduanya.
 * 2. LOKASI ARSIP BERJENJANG, dan kombinasi mustahil ditolak.
 * 3. SURAT DISIMPAN DI DALAM MAP, bukan langsung di ruang/almari/rak.
 * 4. "SUDAH DIBALAS" HARUS MENUNJUK SURAT BALASANNYA.
 * 5. NOMOR SURAT BERURUT PER KLASIFIKASI PER TAHUN, dan tidak pernah
 *    dipakai ulang.
 */
class LetterArchiveTest extends TestCase
{
    use RefreshDatabase;

    private LetterService $surat;

    private LetterMasterService $master;

    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->surat = app(LetterService::class);
        $this->master = app(LetterMasterService::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-tu-arsip', 'name' => 'Petugas Arsip',
            'password' => 'password', 'is_active' => true,
        ]);
        $this->petugas->roles()->attach(Role::query()->where('code', 'petugas-tu')->firstOrFail());
    }

    // ============================================== sifat vs derajat

    #[Test]
    public function surat_bisa_rahasia_dan_mendesak_sekaligus(): void
    {
        /*
         * Satu kolom lama hanya bisa menyimpan salah satunya. Kalau petugas
         * memilih 'rahasia', kecepatannya hilang dan surat itu mengantre
         * seperti surat biasa; kalau memilih 'segera', keterbatasan aksesnya
         * hilang. Tidak ada pilihan yang benar — dan itu tanda kolomnya yang
         * salah, bukan pengisinya.
         */
        $surat = $this->terima(['security' => 'rahasia', 'urgency' => 'amat-segera']);

        $this->assertSame('rahasia', $surat->security);
        $this->assertSame('amat-segera', $surat->urgency);
    }

    #[Test]
    public function sifat_di_luar_daftar_tata_naskah_ditolak(): void
    {
        // 'penting' bukan sifat maupun derajat pada tata naskah dinas; ia
        // peninggalan kosakata lama yang sudah dipetakan saat migrasi.
        $this->expectException(QueryException::class);

        $this->terima(['security' => 'penting']);
    }

    // ============================================== lokasi berjenjang

    #[Test]
    public function lokasi_arsip_tersusun_berjenjang_dari_ruang_sampai_map(): void
    {
        $map = $this->mapArsip();

        $this->assertSame('Ruang Arsip / Almari B / Rak 3 / Map Kepegawaian', $map->jalur());
    }

    #[Test]
    public function rak_tidak_bisa_langsung_berada_di_dalam_ruang(): void
    {
        $ruang = $this->master->addLocation('ruang', 'R1', 'Ruang Arsip');

        /*
         * Empat daftar datar Khanza tidak bisa menolak ini: kodenya cuma
         * berdampingan pada satu baris surat, jadi rak mana pun boleh
         * dipasangkan dengan ruang mana pun.
         */
        $this->expectException(CorrespondenceException::class);
        $this->expectExceptionMessageMatches('/harus berada di dalam almari/');

        $this->master->addLocation('rak', 'RK1', 'Rak 3', $ruang);
    }

    #[Test]
    public function ruang_tidak_berada_di_dalam_apa_pun(): void
    {
        $ruang = $this->master->addLocation('ruang', 'R1', 'Ruang Arsip');

        $this->expectException(CorrespondenceException::class);
        $this->expectExceptionMessageMatches('/tidak berada di dalam apa pun/');

        $this->master->addLocation('ruang', 'R2', 'Ruang Arsip Lama', $ruang);
    }

    #[Test]
    public function basis_data_menolak_almari_tanpa_induk(): void
    {
        $this->expectException(QueryException::class);

        DB::table('correspondence.letter_locations')->insert([
            'parent_id' => null, 'level' => 'almari', 'code' => 'AL-LIAR',
            'name' => 'Almari tanpa ruang', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    #[Test]
    public function surat_hanya_bisa_disimpan_di_dalam_map(): void
    {
        $ruang = $this->master->addLocation('ruang', 'R1', 'Ruang Arsip');

        // Lokasi "ruang arsip" tidak menuntun siapa pun ke berkasnya.
        $this->expectException(CorrespondenceException::class);
        $this->expectExceptionMessageMatches('/di dalam MAP/');

        $this->terima(['location_id' => $ruang->id]);
    }

    #[Test]
    public function daftar_tempat_penyimpanan_hanya_berisi_map(): void
    {
        $this->mapArsip();

        $pilihan = $this->master->storableLocations();

        $this->assertCount(1, $pilihan);
        $this->assertContains('Ruang Arsip / Almari B / Rak 3 / Map Kepegawaian', $pilihan);
    }

    // ================================================== disposisi

    #[Test]
    public function satu_surat_bisa_didisposisikan_berturut_turut(): void
    {
        $surat = $this->terima();

        $this->surat->dispose($surat, [
            'to_name' => 'Kepala Bidang Pelayanan',
            'instruction' => 'Mohon ditindaklanjuti',
            'due_date' => now()->addDays(3)->toDateString(),
        ], $this->petugas->id);

        $this->surat->dispose($surat->refresh(), [
            'to_name' => 'Koordinator Rawat Jalan',
            'instruction' => 'Siapkan jawaban tertulis',
        ], $this->petugas->id);

        $disposisi = $surat->refresh()->dispositions;

        /*
         * Kolom tunggal forwarded_to hanya bisa menyimpan yang terakhir, dan
         * menghapus jejak siapa meneruskan kepada siapa.
         */
        $this->assertCount(2, $disposisi);
        $this->assertSame([1, 2], $disposisi->pluck('sequence')->all());
        $this->assertSame('Koordinator Rawat Jalan', $surat->forwarded_to);
    }

    #[Test]
    public function disposisi_tanpa_isi_ditolak(): void
    {
        $this->expectException(CorrespondenceException::class);
        $this->expectExceptionMessageMatches('/hanya memindahkan kertas/');

        $this->surat->dispose($this->terima(), ['to_name' => 'Kepala Bidang', 'instruction' => '  '], $this->petugas->id);
    }

    #[Test]
    public function disposisi_yang_lewat_tenggat_terdaftar_dan_dihitung(): void
    {
        $surat = $this->terima();

        $disposisi = $this->surat->dispose($surat, [
            'to_name' => 'Kepala Bidang', 'instruction' => 'Tindak lanjuti',
            'due_date' => now()->addDay()->toDateString(),
        ], $this->petugas->id);

        $this->assertFalse($disposisi->terlambat());
        $this->assertCount(0, $this->surat->overdueDispositions());

        $this->travel(3)->days();

        // Disposisi yang tidak bisa dilaporkan terlambat tidak pernah
        // ditagih siapa pun.
        $this->assertTrue($disposisi->refresh()->terlambat());
        $this->assertCount(1, $this->surat->overdueDispositions());

        $this->travelBack();
    }

    #[Test]
    public function disposisi_yang_sudah_selesai_tidak_ikut_terlambat(): void
    {
        $surat = $this->terima();

        $disposisi = $this->surat->dispose($surat, [
            'to_name' => 'Kepala Bidang', 'instruction' => 'Tindak lanjuti',
            'due_date' => now()->addDay()->toDateString(),
        ], $this->petugas->id);

        $this->surat->completeDisposition($disposisi, 'Sudah dijawab lisan');

        $this->travel(5)->days();

        $this->assertFalse($disposisi->refresh()->terlambat());
        $this->assertCount(0, $this->surat->overdueDispositions());

        $this->travelBack();
    }

    #[Test]
    public function disposisi_tanpa_tenggat_tidak_pernah_terhitung_terlambat(): void
    {
        $surat = $this->terima();

        $disposisi = $this->surat->dispose($surat, [
            'to_name' => 'Kepala Bidang', 'instruction' => 'Untuk diketahui',
        ], $this->petugas->id);

        $this->travel(60)->days();

        // Disposisi "untuk diketahui" memang tidak punya tenggat; menghitung
        // ketiadaan tenggat sebagai keterlambatan akan memenuhi daftar
        // tunggakan dengan hal yang tidak perlu dikerjakan siapa pun.
        $this->assertFalse($disposisi->refresh()->terlambat());

        $this->travelBack();
    }

    // ==================================================== balasan

    #[Test]
    public function sudah_dibalas_harus_menunjuk_surat_balasannya(): void
    {
        $masuk = $this->terima([
            'reply_status' => IncomingLetter::BALAS_MENUNGGU,
            'reply_due_date' => now()->addDays(7)->toDateString(),
        ]);

        $balasan = $this->surat->draftOutgoing([
            'recipient' => 'Dinas Kesehatan', 'subject' => 'Jawaban permintaan data',
            'body' => 'Terlampir data yang diminta.',
        ], $this->petugas->id);

        $hasil = $this->surat->markReplied($masuk, $balasan);

        $this->assertSame(IncomingLetter::BALAS_SUDAH, $hasil->reply_status);
        $this->assertSame($balasan->id, $hasil->replied_by_letter_id);

        // Kaitannya dua arah supaya surat balasan bisa menjelaskan dirinya
        // sendiri tanpa menelusuri balik seluruh surat masuk.
        $this->assertSame($masuk->id, $balasan->refresh()->replies_to_letter_id);
    }

    #[Test]
    public function basis_data_menolak_klaim_sudah_dibalas_tanpa_suratnya(): void
    {
        /*
         * Pada saat audit, klaim yang tidak bisa diperiksa dianggap tidak
         * benar. Karena itu ditegakkan CHECK, bukan cuma service.
         */
        $this->expectException(QueryException::class);

        DB::table('correspondence.incoming_letters')->insert([
            'letter_number' => 'SM-UJI-LANGSUNG', 'sender' => 'Dinas', 'subject' => 'Hal',
            'received_at' => now()->toDateString(), 'status' => 'diterima',
            'reply_status' => 'sudah-dibalas', 'replied_by_letter_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    #[Test]
    public function tenggat_balas_hanya_untuk_surat_yang_memang_ditunggu_balasannya(): void
    {
        $this->expectException(CorrespondenceException::class);
        $this->expectExceptionMessageMatches('/kalau balasannya memang ditunggu/');

        $this->terima(['reply_due_date' => now()->addDays(7)->toDateString()]);
    }

    #[Test]
    public function surat_yang_lewat_tenggat_balas_terdaftar(): void
    {
        $masuk = $this->terima([
            'reply_status' => IncomingLetter::BALAS_MENUNGGU,
            'reply_due_date' => now()->addDays(2)->toDateString(),
        ]);

        $this->assertFalse($masuk->terlambatDibalas());

        $this->travel(5)->days();

        $this->assertTrue($masuk->refresh()->terlambatDibalas());
        $this->assertCount(1, $this->surat->overdueReplies());

        $this->travelBack();
    }

    #[Test]
    public function surat_yang_tidak_perlu_dibalas_tidak_bisa_ditandai_dibalas(): void
    {
        $masuk = $this->terima();

        $balasan = $this->surat->draftOutgoing([
            'recipient' => 'Dinas', 'subject' => 'Hal', 'body' => 'Isi',
        ], $this->petugas->id);

        $this->expectException(CorrespondenceException::class);
        $this->expectExceptionMessageMatches('/ditandai tidak perlu dibalas/');

        $this->surat->markReplied($masuk, $balasan);
    }

    // ================================================== penomoran

    #[Test]
    public function nomor_surat_keluar_berurut_per_klasifikasi_per_tahun(): void
    {
        $kepegawaian = $this->master->addClassification('KP.01', 'Kepegawaian');
        $keuangan = $this->master->addClassification('KU.01', 'Keuangan');

        $satu = $this->surat->draftOutgoing($this->isiKeluar(), $this->petugas->id, $kepegawaian);
        $dua = $this->surat->draftOutgoing($this->isiKeluar(), $this->petugas->id, $kepegawaian);
        $tiga = $this->surat->draftOutgoing($this->isiKeluar(), $this->petugas->id, $keuangan);

        $bulan = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'][now()->month - 1];
        $tahun = now()->year;

        /*
         * Nomor surat dinas dibaca sebagai alamat arsip. Hitungan tunggal
         * menghasilkan nomor yang tidak memberi tahu apa pun tentang isinya.
         */
        $this->assertSame("001/KP.01/RSPUI/{$bulan}/{$tahun}", $satu->letter_number);
        $this->assertSame("002/KP.01/RSPUI/{$bulan}/{$tahun}", $dua->letter_number);
        $this->assertSame("001/KU.01/RSPUI/{$bulan}/{$tahun}", $tiga->letter_number);
    }

    #[Test]
    public function nomor_tidak_dipakai_ulang_meski_suratnya_dihapus(): void
    {
        $klasifikasi = $this->master->addClassification('KP.01', 'Kepegawaian');

        $satu = $this->surat->draftOutgoing($this->isiKeluar(), $this->petugas->id, $klasifikasi);
        $satu->delete();

        $dua = $this->surat->draftOutgoing($this->isiKeluar(), $this->petugas->id, $klasifikasi);

        /*
         * Lubang pada urutan adalah informasi: sebuah nomor pernah
         * diterbitkan. Memakainya ulang membuat dua dokumen bernomor sama —
         * kerusakan yang tidak bisa diperbaiki belakangan.
         */
        $this->assertStringStartsWith('002/', $dua->letter_number);
    }

    #[Test]
    public function surat_tanpa_klasifikasi_tetap_bisa_dibuat_dengan_penomoran_lama(): void
    {
        $surat = $this->surat->draftOutgoing($this->isiKeluar(), $this->petugas->id);

        /*
         * Yang TIDAK dilakukan: mengarang kode klasifikasi supaya nomornya
         * kelihatan lengkap. Pola klasifikasi arsip adalah keputusan RSP UI,
         * dan kode karangan akan tercetak pada surat resmi bertahun-tahun.
         */
        $this->assertMatchesRegularExpression('/^SK-\d{4}-\d{5}$/', $surat->letter_number);
        $this->assertNull($surat->classification_id);
    }

    #[Test]
    public function klasifikasi_arsip_hanya_dua_tingkat(): void
    {
        $induk = $this->master->addClassification('KP', 'Kepegawaian');
        $anak = $this->master->addClassification('KP.01', 'Pengadaan Pegawai', $induk);

        $this->expectException(CorrespondenceException::class);
        $this->expectExceptionMessageMatches('/hanya dua tingkat/');

        $this->master->addClassification('KP.01.01', 'Formasi', $anak);
    }

    #[Test]
    public function daftar_klasifikasi_sengaja_lahir_kosong(): void
    {
        /*
         * Pola klasifikasi arsip adalah keputusan RSP UI, sama seperti
         * daftar alasan penolakan anjuran medis pada item A. Garis yang sama
         * dipakai sepanjang proyek: daftar yang ditetapkan di luar rumah
         * sakit boleh disalin, diskresi rumah sakit tidak boleh ditebak.
         */
        $this->assertSame(0, DB::table('correspondence.letter_classifications')->count());
        $this->assertSame(0, DB::table('correspondence.letter_index_terms')->count());
    }

    // -------------------------------------------------------- fixture

    private function terima(array $isi = []): IncomingLetter
    {
        return $this->surat->recordIncoming($isi + [
            'sender' => 'Dinas Kesehatan Provinsi',
            'subject' => 'Permintaan data kunjungan',
            'received_at' => now()->toDateString(),
        ], $this->petugas->id);
    }

    /**
     * @return array<string, string>
     */
    private function isiKeluar(): array
    {
        return [
            'recipient' => 'Dinas Kesehatan Provinsi',
            'subject' => 'Penyampaian data kunjungan',
            'body' => 'Bersama ini disampaikan data yang diminta.',
        ];
    }

    private function mapArsip(): LetterLocation
    {
        $ruang = $this->master->addLocation('ruang', 'R1', 'Ruang Arsip');
        $almari = $this->master->addLocation('almari', 'AL-B', 'Almari B', $ruang);
        $rak = $this->master->addLocation('rak', 'RK-3', 'Rak 3', $almari);

        return $this->master->addLocation('map', 'MP-KP', 'Map Kepegawaian', $rak);
    }
}
