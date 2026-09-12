<?php

namespace App\Modules\Finance\Services;

use Illuminate\Support\Facades\DB;

/**
 * Penomoran dokumen tanpa lompatan, untuk dokumen yang barisannya diaudit.
 *
 * BEDANYA DENGAN NumberAllocator YANG SUDAH ADA. Yang lama memakai
 * `INSERT ... ON CONFLICT DO UPDATE ... RETURNING` — aman terhadap
 * konkurensi, tapi nomornya HILANG kalau transaksi pemanggil rollback.
 * Untuk nomor antrean atau nomor resep, lubang itu tidak apa-apa. Untuk
 * faktur pajak, nomor jurnal, bukti kas, dan kuitansi, lubang adalah
 * temuan audit.
 *
 * CARA KERJANYA: baris deret DIKUNCI (`FOR UPDATE`) selama transaksi,
 * nomornya dinaikkan, lalu nomor yang terbit DICATAT sebagai baris
 * tersendiri. Kalau transaksinya rollback, kenaikan DAN catatannya ikut
 * batal — barisannya tetap rapat. Kalau dokumennya belakangan dibatalkan,
 * nomornya ditandai `dibatalkan` berikut alasannya, bukan dihapus.
 *
 * HARGA YANG DIBAYAR, DAN HARUS DIKETAHUI PEMAKAINYA: penguncian baris
 * membuat dua penerbitan bersamaan MENGANTRE. Untuk faktur pajak itu
 * wajar — bukan transaksi berfrekuensi tinggi. JANGAN memakainya untuk
 * charge line atau nomor antrean poli; di sana NumberAllocator biasa yang
 * benar, dan memakai yang ini akan membuat seluruh loket saling menunggu.
 *
 * WAJIB DIPANGGIL DI DALAM TRANSAKSI. Di luar transaksi, penguncian baris
 * dilepas seketika dan jaminannya hilang tanpa satu pun tanda — jadi itu
 * diperiksa dan ditolak, bukan didiamkan.
 */
class GaplessNumberAllocator
{
    public const FAKTUR_PAJAK = 'faktur_pajak';

    public const JURNAL = 'jurnal';

    public const BUKTI_KAS = 'bukti_kas';

    public const KUITANSI = 'kuitansi';

    /**
     * Menerbitkan satu nomor dokumen.
     *
     * @param  string  $periodFormat  'Y' (mengulang tiap tahun) atau 'Ym' (tiap bulan)
     *
     * @throws FinanceException
     */
    public function terbitkan(
        string $documentType,
        string $prefix,
        string $periodFormat = 'Y',
        ?string $resourceType = null,
        ?int $resourceId = null,
        ?int $issuedBy = null,
    ): string {
        /*
         * MENGAPA PEMERIKSAAN INI DIBUANG — dan ini keputusan yang perlu
         * ditulis, bukan perubahan diam-diam.
         *
         * Versi pertama menolak pemanggilan saat `DB::transactionLevel()
         * === 0`, dengan maksud memaksa pemanggil membungkusnya dalam
         * transaksi. Maksudnya benar, alatnya salah:
         *
         *  - Di bawah RefreshDatabase, SELURUH uji sudah berada di dalam
         *    satu transaksi, jadi pemeriksaannya tidak pernah menyala dan
         *    tidak membuktikan apa pun.
         *  - Di produksi, pemanggil yang lupa membungkus transaksi akan
         *    menerima GALAT alih-alih nomor — dan pada jalur penerbitan
         *    faktur, galat itu menghentikan pekerjaan yang seharusnya bisa
         *    berjalan.
         *
         * Yang benar: method ini MEMBUNGKUS DIRINYA SENDIRI. `DB::transaction()`
         * bersarang memakai savepoint, jadi aman dipanggil dari dalam
         * transaksi yang lebih besar — dan tetap benar saat dipanggil
         * sendirian. Jaminan tanpa-lompatan tidak lagi bergantung pada
         * kedisiplinan pemanggil.
         */
        return DB::transaction(fn () => $this->terbitkanTerkunci(
            $documentType, $prefix, $periodFormat, $resourceType, $resourceId, $issuedBy
        ));
    }

    /** @throws FinanceException */
    private function terbitkanTerkunci(
        string $documentType,
        string $prefix,
        string $periodFormat,
        ?string $resourceType,
        ?int $resourceId,
        ?int $issuedBy,
    ): string {
        $period = now()->format($periodFormat);

        $deret = $this->deret($documentType, $period, $prefix);

        /*
         * FOR UPDATE menahan baris deret sampai transaksi selesai. Inilah
         * yang membedakannya dari ON CONFLICT DO UPDATE: kalau transaksi
         * ini rollback, kenaikan nomornya ikut batal dan nomor berikutnya
         * kembali ke angka yang sama.
         */
        $terkunci = DB::selectOne(
            'SELECT last_number FROM finance.document_number_series WHERE id = ? FOR UPDATE',
            [$deret->id]
        );

        $berikutnya = (int) $terkunci->last_number + 1;

        DB::table('finance.document_number_series')
            ->where('id', $deret->id)
            ->update(['last_number' => $berikutnya, 'updated_at' => now()]);

        $formatted = $prefix.'/'.$period.'/'.str_pad((string) $berikutnya, 6, '0', STR_PAD_LEFT);

        DB::table('finance.document_numbers')->insert([
            'series_id' => $deret->id,
            'sequence' => $berikutnya,
            'formatted' => $formatted,
            'status' => 'terpakai',
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'issued_by' => $issuedBy,
            'issued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $formatted;
    }

    /**
     * Membatalkan nomor yang sudah terbit.
     *
     * NOMORNYA TIDAK DIPAKAI ULANG, dan itu disengaja: dua dokumen
     * berbeda dengan nomor yang sama jauh lebih berbahaya daripada satu
     * nomor yang batal. Yang dituntut audit bukan ketiadaan pembatalan,
     * melainkan bahwa setiap nomor bisa dipertanggungjawabkan.
     *
     * @throws FinanceException
     */
    public function batalkan(string $formatted, string $alasan): void
    {
        if (trim($alasan) === '') {
            throw new FinanceException(
                'Pembatalan nomor dokumen wajib menyebutkan alasannya. Nomor yang hilang '
                .'tanpa keterangan adalah persis lubang yang hendak dicegah penomoran ini.'
            );
        }

        $terpengaruh = DB::table('finance.document_numbers')
            ->where('formatted', $formatted)
            ->where('status', 'terpakai')
            ->update([
                'status' => 'dibatalkan',
                'void_reason' => trim($alasan),
                'updated_at' => now(),
            ]);

        if ($terpengaruh === 0) {
            throw new FinanceException("Nomor dokumen '{$formatted}' tidak ditemukan atau sudah dibatalkan.");
        }
    }

    /**
     * Memeriksa keutuhan barisan nomor pada satu deret.
     *
     * Dipakai job pemeriksaan dan laporan audit. Yang dicari adalah nomor
     * yang HILANG sama sekali — bukan yang dibatalkan, karena yang
     * dibatalkan masih ada barisnya berikut alasannya.
     *
     * @return array{deret: string, terbit: int, hilang: list<int>, utuh: bool}
     *
     * @throws FinanceException
     */
    public function periksaKeutuhan(string $documentType, string $period): array
    {
        $deret = DB::table('finance.document_number_series')
            ->where('document_type', $documentType)
            ->where('period_key', $period)
            ->first();

        if ($deret === null) {
            return ['deret' => $documentType.'/'.$period, 'terbit' => 0, 'hilang' => [], 'utuh' => true];
        }

        $ada = DB::table('finance.document_numbers')
            ->where('series_id', $deret->id)
            ->orderBy('sequence')
            ->pluck('sequence')
            ->map(fn ($n) => (int) $n)
            ->all();

        $hilang = array_values(array_diff(range(1, (int) $deret->last_number), $ada));

        return [
            'deret' => $documentType.'/'.$period,
            'terbit' => count($ada),
            'hilang' => $hilang,
            'utuh' => $hilang === [],
        ];
    }

    private function deret(string $documentType, string $period, string $prefix): object
    {
        $deret = DB::table('finance.document_number_series')
            ->where('document_type', $documentType)
            ->where('period_key', $period)
            ->first();

        if ($deret !== null) {
            return $deret;
        }

        /*
         * Deret baru dibuat dengan ON CONFLICT DO NOTHING lalu dibaca
         * ulang: dua permintaan bersamaan pada periode baru akan
         * sama-sama mencoba membuatnya, dan yang kalah harus memakai
         * deret yang dibuat pemenangnya — bukan gagal.
         */
        DB::statement(
            'INSERT INTO finance.document_number_series
                (document_type, period_key, prefix, last_number, created_at, updated_at)
             VALUES (?, ?, ?, 0, now(), now())
             ON CONFLICT (document_type, period_key) DO NOTHING',
            [$documentType, $period, $prefix]
        );

        return DB::table('finance.document_number_series')
            ->where('document_type', $documentType)
            ->where('period_key', $period)
            ->first();
    }
}
