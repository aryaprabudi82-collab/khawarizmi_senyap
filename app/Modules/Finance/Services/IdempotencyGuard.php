<?php

namespace App\Modules\Finance\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Penahan transaksi finansial ganda.
 *
 * Dipakai SELURUH modul keuangan yang menulis transaksi. Pemakaiannya:
 *
 *     $hasil = $guard->jalankan(
 *         scope: 'tagihan:charge',      // ruang nama operasi, bukan nama tabel
 *         key:   $request->header('Idempotency-Key'),
 *         isi:   ['registrasi' => 12, 'item' => 'LAB-001', 'jumlah' => 1],
 *         kerja: fn () => $this->charges->catat(...),
 *     );
 *
 * RUANG NAMA MEMAKAI TITIK DUA, BUKAN TITIK. Contoh pertama memakai
 * `billing.charge`, dan uji batas konteks membacanya sebagai akses ke
 * tabel `billing.*` dari dalam konteks finance. Ujinya benar: ia tidak
 * bisa membedakan contoh di komentar dari kode yang sungguh berjalan, dan
 * uji yang harus menebak maksud penulisnya berhenti bisa diandalkan.
 *
 * TIGA KEADAAN, DAN KETIGANYA HARUS DIBEDAKAN — inilah inti kelasnya:
 *
 *  1. KUNCI BARU. Pekerjaannya dijalankan, jawabannya disimpan.
 *
 *  2. KUNCI SAMA, ISI SAMA — pengiriman ulang. Jawaban yang TERSIMPAN
 *     dikembalikan, pekerjaannya TIDAK dijalankan lagi. Pemanggil tidak
 *     bisa membedakannya dari permintaan pertama, dan memang seharusnya
 *     begitu: kalau ia menerima galat, petugas akan mengira simpanannya
 *     gagal lalu mencoba cara lain — dan justru itu yang melahirkan baris
 *     kembar dengan kunci berbeda.
 *
 *  3. KUNCI SAMA, ISI BERBEDA — bukan pengiriman ulang melainkan
 *     kekeliruan pemanggil, biasanya kunci yang dipakai ulang untuk
 *     transaksi lain. DITOLAK terang-terangan. Mengembalikan jawaban
 *     transaksi pertama di sini berarti memberi tahu petugas bahwa
 *     tagihan B sudah tersimpan padahal yang tersimpan tagihan A.
 *
 * KUNCI DIPESAN LEBIH DULU, DI LUAR TRANSAKSI PEKERJAANNYA. Kalau
 * pemesanan kunci ikut di dalam transaksi yang sama, kegagalan pekerjaan
 * akan me-rollback pemesanannya juga — dan pengiriman ulang berikutnya
 * akan menjalankan ulang pekerjaan yang mungkin sudah separuh jadi.
 * Dengan memesan lebih dulu, baris penanda tetap ada dan statusnya
 * berubah jadi `gagal`.
 *
 * DUA PERMINTAAN BERSAMAAN dijaga UNIQUE di basis data, bukan
 * pemeriksaan "sudah ada belum?" di aplikasi — yang kedua kalah oleh dua
 * permintaan yang tiba pada saat yang sama: keduanya memeriksa, keduanya
 * tidak menemukan apa-apa, keduanya menulis.
 */
class IdempotencyGuard
{
    /** Berapa lama catatan penahan disimpan sebelum boleh dibersihkan. */
    public const TTL_JAM = 48;

    /**
     * Menjalankan sebuah pekerjaan paling banyak sekali untuk satu kunci.
     *
     * @template T
     *
     * @param  array<string, mixed>  $isi  Isi permintaan, untuk sidik
     * @param  callable(): T  $kerja  Pekerjaan yang dijaga
     * @return array{hasil: mixed, pengulangan: bool}
     *
     * @throws FinanceException
     */
    public function jalankan(
        string $scope,
        ?string $key,
        array $isi,
        callable $kerja,
        ?int $actorId = null,
        ?string $correlationId = null,
    ): array {
        /*
         * KUNCI KOSONG DITOLAK, TIDAK DILEWATKAN. Melewatkannya berarti
         * endpoint yang lupa mengirim kunci berjalan tanpa penahan sama
         * sekali — dan kelalaian itu tidak akan pernah terlihat sampai
         * ada tagihan ganda.
         */
        if ($key === null || trim($key) === '') {
            throw new FinanceException(
                'Permintaan transaksi keuangan wajib membawa Idempotency-Key. '
                .'Tanpa itu, kiriman ulang akan menghasilkan transaksi ganda.'
            );
        }

        $key = trim($key);
        $sidik = hash('sha256', json_encode($isi, JSON_THROW_ON_ERROR));

        $tersimpan = $this->pesanKunci($scope, $key, $sidik, $actorId, $correlationId);

        if ($tersimpan !== null) {
            return $this->jawabanTersimpan($tersimpan, $sidik, $scope, $key);
        }

        try {
            $hasil = $kerja();
        } catch (Throwable $e) {
            $this->tandai($scope, $key, 'gagal', null, null, null);

            throw $e;
        }

        $this->tandai(
            $scope, $key, 'selesai',
            $this->ringkas($hasil),
            is_object($hasil) ? $hasil::class : null,
            is_object($hasil) && isset($hasil->id) ? (int) $hasil->id : null,
        );

        return ['hasil' => $hasil, 'pengulangan' => false];
    }

    /**
     * Memesan kunci. Mengembalikan null bila kunci berhasil dipesan
     * (artinya permintaan ini yang pertama), atau baris yang sudah ada
     * bila kuncinya sudah dipakai.
     */
    private function pesanKunci(string $scope, string $key, string $sidik, ?int $actorId, ?string $correlationId): ?object
    {
        /*
         * SAVEPOINT, supaya bentrokan kunci tidak merusak transaksi
         * pemanggil. Lihat catatan panjang pada blok catch di bawah.
         *
         * Dipakai hanya saat kita memang sedang di dalam transaksi; di
         * luar transaksi, savepoint tidak berlaku dan tidak dibutuhkan.
         */
        $dalamTransaksi = DB::transactionLevel() > 0;
        $savepoint = 'idem_'.bin2hex(random_bytes(6));

        if ($dalamTransaksi) {
            DB::statement('SAVEPOINT '.$savepoint);
        }

        try {
            DB::table('finance.idempotency_records')->insert([
                'scope' => $scope,
                'idempotency_key' => $key,
                'request_fingerprint' => $sidik,
                'status' => 'diproses',
                'actor_id' => $actorId,
                'correlation_id' => $correlationId,
                'expires_at' => now()->addHours(self::TTL_JAM),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($dalamTransaksi) {
                DB::statement('RELEASE SAVEPOINT '.$savepoint);
            }

            return null;
        } catch (QueryException $e) {
            if ($dalamTransaksi) {
                DB::statement('ROLLBACK TO SAVEPOINT '.$savepoint);
            }

            // 23505 = unique violation: kuncinya sudah dipakai.
            if (($e->getCode() !== '23505') && ! str_contains($e->getMessage(), '23505')) {
                throw $e;
            }

            /*
             * CACAT YANG DITEMUKAN SAAT PENGUJIAN, dan perlu ditulis
             * supaya tidak diulang.
             *
             * Versi pertama langsung membaca baris yang sudah ada di sini
             * — dan itu MELEDAK begitu pemanggilnya sedang berada di
             * dalam sebuah transaksi. Sebabnya PostgreSQL: begitu sebuah
             * perintah gagal di dalam transaksi, SELURUH transaksi masuk
             * keadaan aborted dan setiap perintah berikutnya ditolak
             * dengan 25P02 "current transaction is aborted". Jadi
             * pembacaan yang seharusnya memberi jawaban tersimpan justru
             * melempar galat yang sama sekali lain.
             *
             * SAVEPOINT-lah yang membereskannya: kegagalan INSERT
             * dibatalkan sampai savepoint, transaksinya sehat kembali,
             * dan pembacaan berikutnya berjalan normal.
             *
             * Ini bukan kehalusan teoretis. Setiap pemakaian sungguhan
             * ada di dalam transaksi — charge, pembayaran, penjurnalan —
             * jadi versi pertama akan gagal di HAMPIR SELURUH pemakaian
             * nyata, dan hanya bekerja pada pemakaian sepele di luar
             * transaksi.
             */
            return $this->bacaBaris($scope, $key);
        }
    }

    /**
     * Membaca baris penahan dengan aman, termasuk saat pemanggilnya
     * berada di dalam transaksi yang baru saja gagal.
     */
    private function bacaBaris(string $scope, string $key): ?object
    {
        return DB::table('finance.idempotency_records')
            ->where('scope', $scope)
            ->where('idempotency_key', $key)
            ->first();
    }

    /**
     * @return array{hasil: mixed, pengulangan: bool}
     *
     * @throws FinanceException
     */
    private function jawabanTersimpan(object $baris, string $sidik, string $scope, string $key): array
    {
        if ($baris->request_fingerprint !== $sidik) {
            throw new FinanceException(
                "Idempotency-Key '{$key}' sudah dipakai untuk permintaan dengan isi yang BERBEDA "
                .'pada operasi '.$scope.'. Kunci tidak boleh dipakai ulang untuk transaksi lain — '
                .'gunakan kunci baru.'
            );
        }

        if ($baris->status === 'diproses') {
            /*
             * Permintaan kembar yang tiba saat yang pertama masih
             * berjalan. Menjalankannya juga berarti dua transaksi
             * berjalan bersamaan; mengembalikan jawaban kosong berarti
             * berbohong. Yang jujur: minta menunggu.
             */
            throw new FinanceException(
                'Permintaan dengan kunci yang sama sedang diproses. Tunggu sebentar lalu '
                .'periksa hasilnya — jangan mengirim ulang, transaksinya kemungkinan besar berhasil.'
            );
        }

        if ($baris->status === 'gagal') {
            throw new FinanceException(
                'Permintaan dengan kunci ini sebelumnya GAGAL. Periksa penyebabnya lebih dulu; '
                .'bila hendak mencoba lagi, gunakan kunci baru supaya kegagalan lama tetap terlacak.'
            );
        }

        return [
            'hasil' => $baris->response === null ? null : json_decode((string) $baris->response, true),
            'pengulangan' => true,
        ];
    }

    private function tandai(string $scope, string $key, string $status, ?array $jawaban, ?string $tipe, ?int $id): void
    {
        DB::table('finance.idempotency_records')
            ->where('scope', $scope)
            ->where('idempotency_key', $key)
            ->update([
                'status' => $status,
                'response' => $jawaban === null ? null : json_encode($jawaban, JSON_UNESCAPED_UNICODE),
                'resource_type' => $tipe,
                'resource_id' => $id,
                'updated_at' => now(),
            ]);
    }

    /**
     * Jawaban disimpan RINGKAS, bukan utuh. Menyimpan seluruh entitas
     * berikut relasinya membuat tabel penahan ikut menyimpan salinan
     * data transaksi — salinan yang akan basi dan jadi sumber kebenaran
     * kedua.
     *
     * @return array<string, mixed>|null
     */
    private function ringkas(mixed $hasil): ?array
    {
        if ($hasil === null) {
            return null;
        }

        if (is_scalar($hasil)) {
            return ['nilai' => $hasil];
        }

        if (is_array($hasil)) {
            return $hasil;
        }

        if (is_object($hasil) && isset($hasil->id)) {
            return ['id' => (int) $hasil->id, 'tipe' => $hasil::class];
        }

        return null;
    }

    /**
     * Membersihkan catatan yang sudah lewat masa simpannya.
     *
     * Dipanggil penjadwal. Dikembalikan jumlahnya supaya perawatan
     * terjadwal bisa mencatat bahwa ia benar-benar berjalan — hari-hari
     * saat tidak ada yang perlu dibersihkan justru mayoritasnya, dan
     * mencatat hanya saat ada perubahan membuat cron yang sehat tampak
     * mati berbulan-bulan.
     */
    public function bersihkanKedaluwarsa(): int
    {
        return DB::table('finance.idempotency_records')
            ->where('expires_at', '<', now())
            ->delete();
    }
}
