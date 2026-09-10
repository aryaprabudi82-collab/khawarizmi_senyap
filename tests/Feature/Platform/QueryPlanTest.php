<?php

namespace Tests\Feature\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ketersediaan indeks pada kueri jalur panas (kesiapan 2.000 pasien/hari).
 *
 * MENGAPA UJI INI ADA. Seluruh uji lain di repositori ini menguji KEBENARAN:
 * apakah hasilnya betul. Tidak satu pun menguji apakah hasil yang betul itu
 * diperoleh dengan cara yang masih sanggup berjalan di volume sungguhan —
 * dan basis data uji berisi belasan baris, jadi pemindaian penuh atas
 * seluruh tabel pun selesai dalam sepersekian milidetik dan lolos tanpa
 * jejak. Pada 600.000 kunjungan setahun perbedaannya berhenti jadi soal
 * kerapian: layar antrean apotek menyegarkan diri tiap dua puluh detik, dan
 * kotak cari pasien dipanggil setiap ketikan di setiap loket.
 *
 * CARANYA: `SET enable_seqscan = off`, LALU EXPLAIN.
 *
 * Pada tabel uji yang kosong, PostgreSQL memilih Seq Scan untuk SEMUANYA —
 * dan itu keputusan yang benar, karena membaca sepuluh baris memang lebih
 * murah daripada membuka indeks. Jadi rencana apa adanya tidak bisa
 * membedakan "tidak ada indeks" dari "tabelnya kebetulan kosong".
 *
 * Dengan seqscan dimatikan, perencana WAJIB memakai indeks kalau ada yang
 * bisa dipakai; ia hanya kembali ke Seq Scan bila benar-benar tidak ada
 * satu pun indeks yang melayani predikat itu. Jadi "tetap Seq Scan padahal
 * seqscan dimatikan" adalah bukti pasti bahwa indeksnya tidak ada — dan itu
 * tidak bergantung pada isi tabel maupun beban mesin.
 *
 * BATAS YANG PERLU JUJUR DISEBUT: uji ini membuktikan indeksnya ADA dan
 * BISA dipakai, bukan bahwa perencana akan memilihnya di produksi. Yang
 * kedua bergantung statistik nyata dan hanya bisa diukur di sana. Yang
 * dijaga di sini adalah kemunduran yang paling mungkin dan paling sunyi:
 * seseorang menghapus indeks, atau menulis ulang kueri jadi bentuk yang
 * tidak bisa memakainya.
 */
class QueryPlanTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Rencana eksekusi dengan Seq Scan dimatikan.
     *
     * @return string rencana lengkap sebagai satu teks
     */
    private function rencanaTanpaSeqScan(string $sql, array $bind = []): string
    {
        DB::statement('SET LOCAL enable_seqscan = off');

        $baris = DB::select('EXPLAIN '.$sql, $bind);

        return implode("\n", array_map(fn ($r) => (array_values((array) $r))[0], $baris));
    }

    private function assertMemakaiIndeks(string $rencana, string $pesan): void
    {
        $this->assertStringNotContainsString('Seq Scan', $rencana,
            $pesan."\n\nSeq Scan tetap dipilih PADAHAL enable_seqscan dimatikan — artinya "
            ."tidak ada satu pun indeks yang bisa melayani predikat ini.\n\n".$rencana);
    }

    #[Test]
    public function pencarian_nama_pasien_punya_indeks_trigram(): void
    {
        /*
         * `ilike '%budi%'` — wildcard DI DEPAN. Indeks B-tree sama sekali
         * tidak bisa melayaninya: ia mengurutkan dari huruf pertama, jadi
         * "nama yang MENGANDUNG budi" tidak punya titik mulai. Satu-satunya
         * yang menolong adalah GIN trigram.
         */
        $rencana = $this->rencanaTanpaSeqScan(
            'select * from identity.patients where name ilike ?', ['%budi%']
        );

        $this->assertMemakaiIndeks($rencana,
            'Pencarian nama pasien tidak punya indeks yang bisa dipakai. Pada 600 ribu '
            .'pasien ini dijalankan di setiap ketikan, di setiap loket.');

        $this->assertStringContainsString('trgm', $rencana,
            "Indeks yang dipakai bukan trigram:\n".$rencana);
    }

    #[Test]
    public function daftar_resep_harian_punya_indeks(): void
    {
        /*
         * Bentuk yang dipakai layar antrean apotek setelah diperbaiki:
         * rentang setengah terbuka, bukan `whereDate`.
         */
        $rencana = $this->rencanaTanpaSeqScan(
            'select * from pharmacy.prescriptions
              where prescribed_at >= ? and prescribed_at < ? and deleted_at is null',
            ['2026-09-10 00:00:00', '2026-09-11 00:00:00']
        );

        $this->assertMemakaiIndeks($rencana,
            'Daftar resep harian tidak punya indeks yang bisa dipakai. Layar antrean '
            .'apotek menyegarkan diri tiap 20 detik.');
    }

    #[Test]
    public function bentuk_where_date_pada_timestamp_memang_tidak_bisa_memakai_indeks(): void
    {
        /*
         * Uji ini menguji SEBAB-nya, bukan cuma perbaikannya — supaya kalau
         * suatu hari ada yang menulis ulang `whereDate` di jalur panas,
         * alasan penggantiannya tidak perlu ditemukan ulang dari nol.
         *
         * Bentuk yang SALAH tetap dijalankan di sini dan dipastikan memang
         * tidak bisa memakai indeks, bahkan saat Seq Scan dimatikan. Kalau
         * suatu saat PostgreSQL jadi cukup pintar untuk mengoptimalkannya,
         * uji inilah yang akan memberitahu — dan makro whereOnDate boleh
         * ditinjau ulang.
         */
        $buruk = $this->rencanaTanpaSeqScan(
            'select * from pharmacy.prescriptions where prescribed_at::date = ?',
            ['2026-09-10']
        );

        $this->assertStringContainsString('Seq Scan', $buruk,
            'Diharapkan `kolom::date = ?` pada timestamptz TIDAK bisa memakai indeks; '
            .'ternyata bisa. Kalau PostgreSQL sudah mengoptimalkannya, tinjau ulang '
            ."makro whereOnDate.\n\n".$buruk);
    }

    #[Test]
    public function bentuk_where_date_pada_kolom_date_tetap_bisa_memakai_indeks(): void
    {
        /*
         * Pasangan uji di atas, dan alasannya penting: TIDAK semua
         * `whereDate` bermasalah. Pada kolom bertipe `date`, PostgreSQL
         * membuang cast yang tidak berguna dan indeksnya tetap terpakai.
         *
         * Tanpa uji ini, orang berikutnya yang membaca makro whereOnDate
         * akan menyimpulkan "ganti semua whereDate" lalu mengubah tujuh
         * layar pendaftaran tanpa memperbaiki apa pun.
         */
        $rencana = $this->rencanaTanpaSeqScan(
            'select * from encounter.registrations where service_date::date = ?',
            ['2026-09-10']
        );

        $this->assertMemakaiIndeks($rencana,
            'whereDate pada kolom bertipe `date` seharusnya tetap bisa memakai indeks.');
    }

    #[Test]
    public function baris_resep_punya_indeks_ke_induknya(): void
    {
        /*
         * PostgreSQL TIDAK mengindeks kolom foreign key secara otomatis —
         * berbeda dari MySQL/InnoDB. Tanpa indeks eksplisit, membuka satu
         * resep memindai seluruh tabel baris resep, yang tumbuh sekitar
         * enam juta baris setahun pada 2.000 pasien/hari.
         */
        $rencana = $this->rencanaTanpaSeqScan(
            'select * from pharmacy.prescription_items where prescription_id = ?', [1]
        );

        $this->assertMemakaiIndeks($rencana,
            'Baris resep tidak punya indeks ke resep induknya. Ini dijalankan setiap '
            .'kali satu resep dibuka, ditelaah, atau diserahkan.');
    }

    #[Test]
    public function penutupan_kasir_punya_indeks_pembayaran(): void
    {
        // Dipakai CashierClosingService::recordedInWindow() setiap penutupan
        // shift, dan rentangnya sempit — indeks memang yang benar di sini.
        $rencana = $this->rencanaTanpaSeqScan(
            'select * from billing.payments where paid_at >= ? and paid_at < ?',
            ['2026-09-10 07:00:00', '2026-09-10 14:00:00']
        );

        $this->assertMemakaiIndeks($rencana,
            'Penutupan shift kasir tidak punya indeks atas waktu pembayaran.');
    }

    #[Test]
    public function daftar_kunjungan_harian_punya_indeks(): void
    {
        // Layar pendaftaran, dibuka terus-menerus sepanjang jam layanan.
        $rencana = $this->rencanaTanpaSeqScan(
            'select * from encounter.registrations where service_date = ? and status <> ?',
            ['2026-09-10', 'batal']
        );

        $this->assertMemakaiIndeks($rencana,
            'Daftar kunjungan harian tidak punya indeks atas tanggal layanan.');
    }

    #[Test]
    public function tagihan_harian_kasir_punya_indeks(): void
    {
        // Layar kasir rawat jalan, dibuka sepanjang hari.
        $rencana = $this->rencanaTanpaSeqScan(
            'select * from billing.invoices where opened_at >= ? and opened_at < ?',
            ['2026-09-10 00:00:00', '2026-09-11 00:00:00']
        );

        $this->assertMemakaiIndeks($rencana,
            'Daftar tagihan harian tidak punya indeks atas waktu tagihan dibuka.');
    }
}
