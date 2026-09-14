<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Services\PostingOutbox;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Outbox Posting Engine — kerangka, Wave 1 butir 1.11.
 *
 * YANG PALING PERLU DIKUNCI: baris outbox ditulis DI DALAM transaksi
 * bisnisnya, sehingga ia batal bersama transaksinya. Itulah yang menutup
 * dua lubang yang hanya muncul saat sistem sedang sibuk:
 *
 *   - Transaksi commit lalu proses mati sebelum job terkirim: transaksi
 *     ada, jurnal tidak akan pernah ada, tanpa satu pun galat.
 *   - Job terkirim lalu transaksi rollback: worker menjurnalkan transaksi
 *     yang tidak pernah terjadi.
 */
class PostingOutboxTest extends TestCase
{
    use RefreshDatabase;

    private PostingOutbox $outbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->outbox = app(PostingOutbox::class);
    }

    // ------------------------------------------------------------ pencatatan

    /**
     * INTI OUTBOX. Transaksi yang dibatalkan tidak meninggalkan niat
     * posting — kalau meninggalkan, worker akan menjurnalkan transaksi
     * yang tidak pernah terjadi.
     */
    #[Test]
    public function transaksi_yang_rollback_tidak_meninggalkan_niat_posting(): void
    {
        try {
            DB::transaction(function () {
                $this->catat('invoice.diterbitkan', 1);
                throw new \RuntimeException('batal di tengah');
            });
        } catch (\RuntimeException) {
            // diharapkan
        }

        $this->assertSame(0, DB::table('finance.posting_outbox')->count(),
            'Niat posting harus ikut batal bersama transaksinya');
    }

    #[Test]
    public function transaksi_yang_berhasil_meninggalkan_niat_posting(): void
    {
        DB::transaction(fn () => $this->catat('invoice.diterbitkan', 1));

        $this->assertSame(1, DB::table('finance.posting_outbox')->count());
        $this->assertSame(PostingOutbox::MENUNGGU,
            DB::table('finance.posting_outbox')->value('status'));
    }

    /**
     * Satu peristiwa sumber hanya boleh menghasilkan satu jurnal, berapa
     * kali pun ia dimasukkan ke outbox.
     */
    #[Test]
    public function peristiwa_yang_sama_tidak_bisa_dicatat_dua_kali(): void
    {
        $this->catat('invoice.diterbitkan', 1);

        $this->expectException(QueryException::class);

        $this->catat('invoice.diterbitkan', 1);
    }

    /**
     * `catatBilaBelumAda` MELOLOSKAN yang sudah ada, bukan melempar
     * galat. Pemanggil yang menerima galat akan mengulang seluruh
     * transaksi bisnisnya — dan itulah yang justru melahirkan transaksi
     * ganda.
     */
    #[Test]
    public function catat_bila_belum_ada_meloloskan_yang_sudah_tercatat(): void
    {
        $this->assertTrue($this->catatAman('invoice.diterbitkan', 1));
        $this->assertFalse($this->catatAman('invoice.diterbitkan', 1));

        $this->assertSame(1, DB::table('finance.posting_outbox')->count());
    }

    /** Peristiwa berbeda atas sumber yang sama tetap dua baris. */
    #[Test]
    public function peristiwa_berbeda_atas_sumber_sama_tidak_bertabrakan(): void
    {
        $this->catat('invoice.diterbitkan', 1);
        $this->catat('invoice.dibatalkan', 1);

        $this->assertSame(2, DB::table('finance.posting_outbox')->count());
    }

    // -------------------------------------------------------- pengambilan

    #[Test]
    public function pengambilan_mengunci_dan_menaikkan_percobaan(): void
    {
        $this->catat('invoice.diterbitkan', 1);
        $this->catat('invoice.diterbitkan', 2);

        $diambil = $this->outbox->ambilUntukDiproses();

        $this->assertCount(2, $diambil);

        $baris = DB::table('finance.posting_outbox')->get();
        $this->assertTrue($baris->every(fn ($b) => $b->status === PostingOutbox::DIPROSES));
        $this->assertTrue($baris->every(fn ($b) => $b->attempts === 1));
    }

    #[Test]
    public function baris_yang_sudah_diproses_tidak_diambil_lagi(): void
    {
        $this->catat('invoice.diterbitkan', 1);
        $this->outbox->ambilUntukDiproses();

        $this->assertCount(0, $this->outbox->ambilUntukDiproses());
    }

    // ---------------------------------------------------- selesai & gagal

    #[Test]
    public function baris_selesai_menunjuk_jurnalnya(): void
    {
        $id = $this->catat('invoice.diterbitkan', 1);
        $jurnal = $this->jurnalKosongUntukUji();

        $this->outbox->tandaiSelesai($id, $jurnal);

        $baris = DB::table('finance.posting_outbox')->find($id);
        $this->assertSame(PostingOutbox::SELESAI, $baris->status);
        $this->assertSame($jurnal, (int) $baris->journal_entry_id);
        $this->assertNotNull($baris->processed_at);
    }

    /**
     * Basis data menolak baris selesai yang tidak menunjuk jurnalnya —
     * tanpa itu, tidak ada cara menelusuri dari transaksi ke jurnalnya,
     * dan penelusuran balik itu justru yang dicari saat ada selisih.
     */
    #[Test]
    public function basis_data_menolak_selesai_tanpa_jurnal(): void
    {
        $id = $this->catat('invoice.diterbitkan', 1);

        $this->expectException(QueryException::class);

        DB::table('finance.posting_outbox')->where('id', $id)
            ->update(['status' => PostingOutbox::SELESAI, 'journal_entry_id' => null]);
    }

    #[Test]
    public function basis_data_menolak_gagal_tanpa_alasan(): void
    {
        $id = $this->catat('invoice.diterbitkan', 1);

        $this->expectException(QueryException::class);

        DB::table('finance.posting_outbox')->where('id', $id)
            ->update(['status' => PostingOutbox::GAGAL, 'last_error' => null]);
    }

    /**
     * Kegagalan dijadwalkan ulang dengan jeda BERTAMBAH. Mencobanya lagi
     * seketika menghasilkan lima kegagalan dalam satu detik lalu menyerah
     * — padahal gangguan sesaat pulih dalam setengah menit.
     */
    #[Test]
    public function kegagalan_dijadwalkan_ulang_dengan_jeda_bertambah(): void
    {
        $id = $this->catat('invoice.diterbitkan', 1);

        $this->outbox->ambilUntukDiproses();
        $this->outbox->tandaiGagal($id, 'Akun belum dipetakan');

        $baris = DB::table('finance.posting_outbox')->find($id);

        $this->assertSame(PostingOutbox::MENUNGGU, $baris->status,
            'Kegagalan pertama harus dijadwalkan ulang, bukan langsung menyerah');
        $this->assertNotNull($baris->next_attempt_at);
        $this->assertSame('Akun belum dipetakan', $baris->last_error);
    }

    /**
     * Setelah melewati batas, ia MENYERAH — tidak dicoba selamanya dan
     * tidak dibuang. Yang dicoba selamanya membanjiri log sampai
     * kegagalan lain tidak terlihat; yang dibuang menghilangkan transaksi
     * dari buku besar tanpa jejak.
     */
    #[Test]
    public function setelah_batas_percobaan_menyerah_bukan_dibuang(): void
    {
        $id = $this->catat('invoice.diterbitkan', 1);

        for ($i = 0; $i < PostingOutbox::BATAS_PERCOBAAN; $i++) {
            DB::table('finance.posting_outbox')->where('id', $id)
                ->update(['status' => PostingOutbox::MENUNGGU, 'next_attempt_at' => null]);
            $this->outbox->ambilUntukDiproses();
            $this->outbox->tandaiGagal($id, 'Gagal terus');
        }

        $baris = DB::table('finance.posting_outbox')->find($id);

        $this->assertSame(PostingOutbox::GAGAL, $baris->status);
        $this->assertNull($baris->next_attempt_at);
        $this->assertCount(1, $this->outbox->gagalPermanen(),
            'Baris yang menyerah tetap ada dan dilaporkan, bukan dihapus');
    }

    // ------------------------------------------------------- pemeliharaan

    /**
     * Worker yang mati di tengah meninggalkan barisnya berstatus
     * `diproses` SELAMANYA — tidak ada yang mengambilnya lagi, dan
     * transaksinya tidak pernah sampai ke buku besar tanpa satu pun galat.
     */
    #[Test]
    public function baris_tersangkut_dibebaskan(): void
    {
        $id = $this->catat('invoice.diterbitkan', 1);
        $this->outbox->ambilUntukDiproses();

        DB::table('finance.posting_outbox')->where('id', $id)
            ->update(['updated_at' => now()->subHour()]);

        $this->assertSame(1, $this->outbox->bebaskanYangTersangkut(15));
        $this->assertSame(PostingOutbox::MENUNGGU,
            DB::table('finance.posting_outbox')->find($id)->status);
    }

    #[Test]
    public function baris_yang_baru_diproses_tidak_ikut_dibebaskan(): void
    {
        $this->catat('invoice.diterbitkan', 1);
        $this->outbox->ambilUntukDiproses();

        $this->assertSame(0, $this->outbox->bebaskanYangTersangkut(15));
    }

    #[Test]
    public function ringkasan_antrean_menghitung_tiap_status(): void
    {
        $a = $this->catat('invoice.diterbitkan', 1);
        $this->catat('invoice.diterbitkan', 2);

        $this->outbox->ambilUntukDiproses();
        $this->outbox->tandaiSelesai($a, $this->jurnalKosongUntukUji());

        $r = $this->outbox->ringkasan();

        $this->assertSame(1, $r['selesai']);
        $this->assertSame(1, $r['diproses']);
        $this->assertSame(0, $r['gagal']);
    }

    // ------------------------------------------------------------ pembantu

    private function catat(string $event, int $sourceId): int
    {
        return $this->outbox->catat(
            eventType: $event,
            sourceContext: 'billing',
            sourceType: 'invoice',
            sourceId: $sourceId,
            payload: ['total' => '100000.00'],
            occurredOn: now()->toDateString(),
        );
    }

    private function catatAman(string $event, int $sourceId): bool
    {
        return $this->outbox->catatBilaBelumAda(
            eventType: $event,
            sourceContext: 'billing',
            sourceType: 'invoice',
            sourceId: $sourceId,
            payload: ['total' => '100000.00'],
            occurredOn: now()->toDateString(),
        );
    }

    /**
     * Jurnal seimbang seadanya, hanya untuk dipakai sebagai rujukan
     * `journal_entry_id`. Constraint balance menolak jurnal kosong, jadi
     * ia harus benar-benar punya dua baris.
     */
    private function jurnalKosongUntukUji(): int
    {
        $akun = [];

        foreach ([['1-6001', 'kas'], ['4-6001', 'pendapatan']] as [$kode, $jenis]) {
            $akun[] = DB::table('finance.chart_of_accounts')->insertGetId([
                'code' => $kode, 'name' => 'Akun '.$kode, 'type' => $jenis,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return DB::transaction(function () use ($akun) {
            $id = DB::table('finance.journal_entries')->insertGetId([
                'entry_number' => 'UJI-'.uniqid(),
                'entry_date' => now(),
                'description' => 'Jurnal rujukan uji outbox',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('finance.journal_lines')->insert([
                ['journal_entry_id' => $id, 'account_id' => $akun[0], 'debit' => 1000, 'credit' => 0],
                ['journal_entry_id' => $id, 'account_id' => $akun[1], 'debit' => 0, 'credit' => 1000],
            ]);

            return $id;
        });
    }
}
