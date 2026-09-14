<?php

namespace App\Modules\Finance\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Outbox Posting Engine — pencatat NIAT menjurnalkan sebuah peristiwa.
 *
 * DUA TANGGUNG JAWAB YANG SENGAJA DIPISAH:
 *
 *   PostingOutbox  — mencatat bahwa sesuatu PERLU dijurnalkan, dan
 *                    menyerahkan barisnya ke worker. Tidak tahu apa-apa
 *                    tentang akun maupun debit-kredit.
 *
 *   PostingEngine  — mengubah satu baris outbox jadi jurnal. Tidak tahu
 *                    apa-apa tentang antrean maupun percobaan ulang.
 *
 * Pemisahan itu bukan kerapian: pencatatan niat harus berjalan DI DALAM
 * transaksi bisnisnya (supaya batal bersama-sama), sedangkan penjurnalan
 * harus berjalan DI LUARNYA (supaya charge capture tidak menunggu).
 * Menyatukannya memaksa salah satu sifat itu dikorbankan.
 *
 * CARA PAKAI dari sisi konteks lain:
 *
 *     DB::transaction(function () {
 *         $invoice = $this->invoices->simpan(...);
 *
 *         $outbox->catat(
 *             eventType: 'invoice.diterbitkan',
 *             sourceContext: 'billing',
 *             sourceType: 'invoice',
 *             sourceId: $invoice->id,
 *             payload: ['total' => (string) $invoice->total, 'payer_id' => $invoice->payer_id],
 *             occurredOn: $invoice->issued_on,
 *         );
 *     });
 */
class PostingOutbox
{
    public const MENUNGGU = 'menunggu';

    public const DIPROSES = 'diproses';

    public const SELESAI = 'selesai';

    public const GAGAL = 'gagal';

    /**
     * Berapa kali sebuah baris dicoba sebelum menyerah.
     *
     * TIDAK TAK TERHINGGA, dan itu disengaja. Baris yang dicoba selamanya
     * akan membanjiri log sampai kegagalan lain tidak terlihat lagi —
     * dan yang paling sering menyebabkannya adalah kesalahan yang memang
     * tidak akan sembuh sendiri (akun belum dipetakan, periode terkunci).
     */
    public const BATAS_PERCOBAAN = 5;

    /**
     * Mencatat niat menjurnalkan. WAJIB dipanggil di dalam transaksi
     * bisnisnya.
     *
     * Mengembalikan id baris outbox, atau id baris yang SUDAH ada bila
     * peristiwanya pernah dicatat — bukan galat. Pemanggil yang menerima
     * galat di sini akan mengulang seluruh transaksi bisnisnya, dan
     * itulah yang justru melahirkan transaksi ganda.
     */
    public function catat(
        string $eventType,
        string $sourceContext,
        string $sourceType,
        int $sourceId,
        array $payload,
        string $occurredOn,
        ?string $correlationId = null,
    ): int {
        $dedupe = $this->dedupeKey($eventType, $sourceContext, $sourceType, $sourceId);

        $id = DB::table('finance.posting_outbox')->insertGetId([
            'event_type' => $eventType,
            'source_context' => $sourceContext,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'dedupe_key' => $dedupe,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'occurred_on' => $occurredOn,
            'status' => self::MENUNGGU,
            'correlation_id' => $correlationId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * Mencatat niat, dan MELOLOSKAN peristiwa yang sudah pernah dicatat.
     *
     * Dipakai jalur yang bisa dipanggil ulang — mis. sinkronisasi massal.
     * Memakai ON CONFLICT DO NOTHING supaya bentrokan tidak merusak
     * transaksi pemanggil; lihat catatan panjang di IdempotencyGuard soal
     * 25P02.
     */
    public function catatBilaBelumAda(
        string $eventType,
        string $sourceContext,
        string $sourceType,
        int $sourceId,
        array $payload,
        string $occurredOn,
        ?string $correlationId = null,
    ): bool {
        $terpengaruh = DB::table('finance.posting_outbox')->insertOrIgnore([
            'event_type' => $eventType,
            'source_context' => $sourceContext,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'dedupe_key' => $this->dedupeKey($eventType, $sourceContext, $sourceType, $sourceId),
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'occurred_on' => $occurredOn,
            'status' => self::MENUNGGU,
            'correlation_id' => $correlationId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $terpengaruh > 0;
    }

    /**
     * Mengambil sekumpulan baris untuk dikerjakan, dan MENGUNCINYA.
     *
     * `FOR UPDATE SKIP LOCKED` adalah inti method ini: dua worker yang
     * berjalan bersamaan tidak akan mengambil baris yang sama, dan yang
     * kedua tidak menunggu — ia melewati baris terkunci dan mengambil
     * yang berikutnya. Tanpa SKIP LOCKED, worker kedua mengantre di
     * belakang worker pertama dan penambahan worker tidak menambah
     * kecepatan apa pun.
     *
     * @return Collection<int, object>
     */
    public function ambilUntukDiproses(int $batas = 100): Collection
    {
        return DB::transaction(function () use ($batas) {
            $baris = collect(DB::select(
                'SELECT * FROM finance.posting_outbox
                  WHERE status = ?
                    AND (next_attempt_at IS NULL OR next_attempt_at <= now())
                  ORDER BY next_attempt_at NULLS FIRST, id
                  LIMIT ?
                  FOR UPDATE SKIP LOCKED',
                [self::MENUNGGU, $batas]
            ));

            if ($baris->isEmpty()) {
                return $baris;
            }

            DB::table('finance.posting_outbox')
                ->whereIn('id', $baris->pluck('id')->all())
                ->update([
                    'status' => self::DIPROSES,
                    'attempts' => DB::raw('attempts + 1'),
                    'updated_at' => now(),
                ]);

            return $baris;
        });
    }

    public function tandaiSelesai(int $id, int $journalEntryId): void
    {
        DB::table('finance.posting_outbox')->where('id', $id)->update([
            'status' => self::SELESAI,
            'journal_entry_id' => $journalEntryId,
            'processed_at' => now(),
            'last_error' => null,
            'updated_at' => now(),
        ]);
    }

    /**
     * Menandai kegagalan: dijadwalkan ulang, atau menyerah bila sudah
     * melewati batas percobaan.
     *
     * JEDA BERTAMBAH (exponential backoff). Kegagalan yang paling sering
     * terjadi adalah gangguan sesaat, dan mencobanya lagi seketika
     * menghasilkan lima kegagalan dalam satu detik lalu menyerah —
     * padahal gangguannya pulih dalam setengah menit.
     */
    public function tandaiGagal(int $id, string $pesan): void
    {
        $baris = DB::table('finance.posting_outbox')->find($id);

        if ($baris === null) {
            return;
        }

        $menyerah = $baris->attempts >= self::BATAS_PERCOBAAN;

        DB::table('finance.posting_outbox')->where('id', $id)->update([
            'status' => $menyerah ? self::GAGAL : self::MENUNGGU,
            'last_error' => $pesan,
            'next_attempt_at' => $menyerah ? null : now()->addSeconds(30 * (2 ** ($baris->attempts - 1))),
            'updated_at' => now(),
        ]);
    }

    /**
     * Baris yang menyerah — dead letter.
     *
     * TIDAK DIBUANG dan tidak dicoba selamanya. Ia berhenti di sini
     * berikut pesannya, dan dilaporkan: baris yang dibuang menghilangkan
     * transaksi dari buku besar tanpa jejak.
     *
     * @return Collection<int, object>
     */
    public function gagalPermanen(): Collection
    {
        return collect(DB::select(
            'SELECT * FROM finance.posting_outbox WHERE status = ? ORDER BY updated_at DESC',
            [self::GAGAL]
        ));
    }

    /**
     * Ringkasan antrean, untuk pemantauan dan `siap:periksa`.
     *
     * @return array{menunggu: int, diproses: int, selesai: int, gagal: int, tertua_menit: int|null}
     */
    public function ringkasan(): array
    {
        $hitung = DB::table('finance.posting_outbox')
            ->selectRaw('status, count(*) AS n')
            ->groupBy('status')
            ->pluck('n', 'status');

        $tertua = DB::table('finance.posting_outbox')
            ->where('status', self::MENUNGGU)
            ->min('created_at');

        return [
            'menunggu' => (int) ($hitung[self::MENUNGGU] ?? 0),
            'diproses' => (int) ($hitung[self::DIPROSES] ?? 0),
            'selesai' => (int) ($hitung[self::SELESAI] ?? 0),
            'gagal' => (int) ($hitung[self::GAGAL] ?? 0),
            'tertua_menit' => $tertua === null ? null : (int) now()->diffInMinutes($tertua, true),
        ];
    }

    /**
     * Membebaskan baris yang tersangkut di status `diproses`.
     *
     * Worker yang mati di tengah meninggalkan barisnya berstatus
     * `diproses` selamanya — tidak ada yang mengambilnya lagi, dan
     * transaksinya tidak pernah sampai ke buku besar tanpa satu pun
     * galat. Ini yang membebaskannya.
     */
    public function bebaskanYangTersangkut(int $lebihDariMenit = 15): int
    {
        return DB::table('finance.posting_outbox')
            ->where('status', self::DIPROSES)
            ->where('updated_at', '<', now()->subMinutes($lebihDariMenit))
            ->update([
                'status' => self::MENUNGGU,
                'next_attempt_at' => now(),
                'last_error' => 'Dibebaskan otomatis: tersangkut di status diproses lebih dari '
                    .$lebihDariMenit.' menit, kemungkinan worker mati di tengah.',
                'updated_at' => now(),
            ]);
    }

    private function dedupeKey(string $eventType, string $context, string $type, int $id): string
    {
        return $eventType.':'.$context.':'.$type.':'.$id;
    }
}
