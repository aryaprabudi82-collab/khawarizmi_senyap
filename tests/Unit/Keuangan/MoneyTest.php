<?php

namespace Tests\Unit\Keuangan;

use App\Modules\Keuangan\Shared\Domain\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Value object nilai uang — fondasi seluruh domain keuangan.
 *
 * Uji ini murni domain logic: tidak menyentuh basis data sama sekali, dan
 * karena itu berjalan dalam milidetik. Aturan yang dikunci di sini
 * berlaku pada SETIAP rupiah yang bergerak di sistem.
 */
class MoneyTest extends TestCase
{
    // ------------------------------------------------------------ pembuatan

    #[Test]
    public function nilai_dibaca_dan_disajikan_tanpa_kehilangan_ketelitian(): void
    {
        $this->assertSame('1234.56', (string) Money::tagihan('1234.56'));
        $this->assertSame('1234.00', (string) Money::tagihan(1234));
        $this->assertSame('0.00', (string) Money::nol());
        $this->assertSame('-500.25', (string) Money::tagihan('-500.25'));
    }

    /**
     * FLOAT DITOLAK. Menerimanya berarti kesalahan pembulatan sudah terjadi
     * SEBELUM nilainya sampai ke sini — kelas ini tidak bisa
     * memperbaikinya, hanya menyembunyikannya.
     */
    #[Test]
    public function float_ditolak_bukan_dikonversi_diam_diam(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('tidak boleh berasal dari float');

        Money::tagihan(1234.56);
    }

    /**
     * Pecahan yang melebihi skala DITOLAK, bukan dipotong. Memotongnya
     * berarti membuang uang tanpa ada yang memutuskannya.
     */
    #[Test]
    public function pecahan_melebihi_skala_ditolak_bukan_dipotong(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('melebihi skala');

        Money::tagihan('100.567');
    }

    #[Test]
    public function skala_alokasi_menerima_empat_angka_pecahan(): void
    {
        $this->assertSame('100.5678', (string) Money::alokasi('100.5678'));
    }

    // ----------------------------------------------------------- aritmetika

    #[Test]
    public function penjumlahan_dan_pengurangan_tepat(): void
    {
        $a = Money::tagihan('0.10');
        $b = Money::tagihan('0.20');

        // Inilah yang tidak bisa dilakukan float: 0.1 + 0.2 === 0.3
        $this->assertSame('0.30', (string) $a->tambah($b));
        $this->assertSame('-0.10', (string) $a->kurang($b));
    }

    #[Test]
    public function perkalian_dengan_jumlah_item(): void
    {
        $this->assertSame('37500.00', (string) Money::tagihan('12500')->kali(3));
    }

    /**
     * Dua skala berbeda TIDAK BISA dijumlahkan tanpa dinyatakan. Bukan
     * kerewelan: menjumlahkan hasil alokasi berskala 4 langsung ke nilai
     * tagihan adalah cara paling halus memasukkan pecahan sen ke kuitansi.
     */
    #[Test]
    public function skala_berbeda_tidak_bisa_dijumlahkan_diam_diam(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Samakan skalanya lebih dulu');

        Money::tagihan('100')->tambah(Money::alokasi('100'));
    }

    // ----------------------------------------------------------- pembulatan

    #[Test]
    public function pembulatan_setengah_ke_atas(): void
    {
        $this->assertSame('100.13', (string) Money::alokasi('100.1250')->bulatkanKe(2));
        $this->assertSame('100.12', (string) Money::alokasi('100.1249')->bulatkanKe(2));
        $this->assertSame('100.13', (string) Money::alokasi('100.1251')->bulatkanKe(2));
    }

    #[Test]
    public function pembulatan_nilai_negatif_simetris(): void
    {
        $this->assertSame('-100.13', (string) Money::alokasi('-100.1250')->bulatkanKe(2));
    }

    #[Test]
    public function menaikkan_skala_tidak_mengubah_nilai(): void
    {
        $this->assertSame('100.0000', (string) Money::tagihan('100')->bulatkanKe(4));
    }

    // ------------------------------------------------------------ pembagian

    /**
     * INTI SELURUH KELAS INI, dan alasan `bagi()` ada.
     *
     * Rp 1.000.000 dibagi tiga sama rata. Cara naif menghasilkan
     * 333.333,33 x 3 = 999.999,99 — satu sen hilang tanpa ada yang
     * mencatatnya. Dikalikan ribuan transaksi per bulan, ia jadi selisih
     * yang harus dijelaskan seseorang.
     */
    #[Test]
    public function pembagian_tidak_kehilangan_satu_sen_pun(): void
    {
        $total = Money::tagihan('1000000');

        $bagian = $total->bagi(['operator' => 1, 'asisten' => 1, 'anestesi' => 1]);

        $jumlah = array_reduce(
            $bagian,
            fn (Money $t, Money $b) => $t->tambah($b),
            Money::nol()
        );

        $this->assertTrue($total->samaDengan($jumlah),
            'Jumlah seluruh bagian HARUS sama persis dengan nilai asalnya. '
            .'Dapat: '.$jumlah.', seharusnya: '.$total);
    }

    #[Test]
    public function pembagian_menurut_bobot_berbeda(): void
    {
        $bagian = Money::tagihan('1000000')->bagi([
            'operator' => 50, 'asisten' => 30, 'anestesi' => 20,
        ]);

        $this->assertSame('500000.00', (string) $bagian['operator']);
        $this->assertSame('300000.00', (string) $bagian['asisten']);
        $this->assertSame('200000.00', (string) $bagian['anestesi']);
    }

    /**
     * SISA DIBAGIKAN KE BOBOT TERBESAR, dan hasilnya DAPAT DIREPRODUKSI.
     * Pembagian yang hasilnya berubah antar pemanggilan tidak bisa
     * diaudit — dan jasa medis wajib bisa diaudit.
     */
    #[Test]
    public function sisa_pembagian_jatuh_ke_bobot_terbesar_dan_dapat_direproduksi(): void
    {
        $pertama = Money::tagihan('100')->bagi(['a' => 3, 'b' => 2, 'c' => 2]);
        $kedua = Money::tagihan('100')->bagi(['a' => 3, 'b' => 2, 'c' => 2]);

        foreach ($pertama as $kunci => $nilai) {
            $this->assertTrue($nilai->samaDengan($kedua[$kunci]),
                "Bagian '{$kunci}' berbeda antar pemanggilan — hasilnya tidak dapat direproduksi");
        }

        $jumlah = array_reduce($pertama, fn (Money $t, Money $b) => $t->tambah($b), Money::nol());
        $this->assertSame('100.00', (string) $jumlah);
    }

    #[Test]
    public function pembagian_nilai_negatif_juga_utuh(): void
    {
        $total = Money::tagihan('-1000000');
        $bagian = $total->bagi(['a' => 1, 'b' => 1, 'c' => 1]);

        $jumlah = array_reduce($bagian, fn (Money $t, Money $b) => $t->tambah($b), Money::nol());

        $this->assertTrue($total->samaDengan($jumlah));
    }

    /**
     * Bobot nol seluruhnya DITOLAK, tidak dibagi rata diam-diam. Nilai
     * yang tidak punya dasar pembagian adalah keadaan yang harus
     * ditangani pemanggil.
     */
    #[Test]
    public function bobot_nol_seluruhnya_ditolak(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('tidak ada dasar pembagian');

        Money::tagihan('100')->bagi(['a' => 0, 'b' => 0]);
    }

    #[Test]
    public function bobot_negatif_ditolak(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('tidak boleh negatif');

        Money::tagihan('100')->bagi(['a' => 5, 'b' => -2]);
    }

    /**
     * Pembagian berskala 4 lalu dibulatkan ke tagihan: uji ini meniru
     * alur nyata alokasi jasa medis, dan memastikan pembulatannya terjadi
     * SEKALI di ujung, bukan di tiap langkah.
     */
    #[Test]
    public function alur_nyata_alokasi_berskala_empat_lalu_dibulatkan(): void
    {
        $jasa = Money::alokasi('1000000');

        $bagian = $jasa->bagi(['operator' => 45, 'asisten' => 25, 'anestesi' => 20, 'instrumen' => 10]);

        $tagihan = array_map(fn (Money $m) => $m->sebagaiTagihan(), $bagian);

        $jumlah = array_reduce($tagihan, fn (Money $t, Money $b) => $t->tambah($b), Money::nol());

        $this->assertSame('1000000.00', (string) $jumlah);
        $this->assertSame('450000.00', (string) $tagihan['operator']);
    }

    // ------------------------------------------------------------ penyajian

    #[Test]
    public function penyajian_rupiah_untuk_manusia(): void
    {
        $this->assertSame('Rp 1.234.567', Money::tagihan('1234567')->rupiah());
        $this->assertSame('Rp 1.234.567,89', Money::tagihan('1234567.89')->rupiah());
        $this->assertSame('-Rp 5.000', Money::tagihan('-5000')->rupiah());
        $this->assertSame('Rp 0', Money::nol()->rupiah());
    }

    #[Test]
    public function perbandingan_dan_pemeriksaan_keadaan(): void
    {
        $this->assertTrue(Money::tagihan('100')->lebihDari(Money::tagihan('99.99')));
        $this->assertTrue(Money::nol()->nolKah());
        $this->assertTrue(Money::tagihan('-1')->negatifKah());
        $this->assertSame('100.00', (string) Money::tagihan('-100')->absolut());
    }

    // ------------------------------------------------------------ persentase

    #[Test]
    public function persentase_dihitung_pada_skala_alokasi(): void
    {
        // 10% dari 1.000 = 100, pada skala 4.
        $this->assertSame('100.0000', (string) Money::tagihan('1000')->persen('10'));

        // Persen desimal.
        $this->assertSame('125.0000', (string) Money::tagihan('1000')->persen('12.5'));

        // Potongan: persen negatif.
        $this->assertSame('-125.0000', (string) Money::tagihan('1000')->persen('-12.5'));

        $this->assertSame('0.0000', (string) Money::tagihan('1000')->persen('0'));
    }

    /**
     * PEMBULATAN DITUNDA, dan inilah alasannya ada. Markup 25% atas
     * 1.333,33 menghasilkan 333,3325 — pada skala 4. Membulatkannya ke
     * rupiah lebih dulu lalu mengalikan 30 membuat total resep meleset
     * dari hitung ulang mana pun.
     */
    #[Test]
    public function pembulatan_persentase_ditunda_sampai_akhir(): void
    {
        $dasar = Money::tagihan('1333.33');

        $satuanTertunda = $dasar->bulatkanKe(Money::SKALA_ALOKASI)->tambah($dasar->persen('25'));
        $totalTertunda = $satuanTertunda->kali(30)->sebagaiTagihan();

        $satuanDibulatkan = $dasar->tambah($dasar->persen('25')->sebagaiTagihan());
        $totalDibulatkan = $satuanDibulatkan->kali(30);

        // 1.333,3300 + 333,3325 = 1.666,6625; x30 = 49.999,875 -> 49.999,88.
        $this->assertSame('49999.88', (string) $totalTertunda);

        // Dibulatkan lebih dulu: 333,3325 -> 333,33; 1.666,66 x 30 = 49.999,80.
        $this->assertSame('49999.80', (string) $totalDibulatkan);

        $this->assertFalse(
            $totalTertunda->samaDengan($totalDibulatkan),
            'Kalau keduanya sama, uji ini tidak membuktikan apa pun — angkanya harus dipilih '
            .'supaya selisih pembulatannya benar-benar muncul'
        );
    }

    /**
     * Persen sebagai FLOAT tidak diterima — alasannya sama dengan seluruh
     * kelas ini: 12.5 sebagai float bukan betul-betul 12,5.
     */
    #[Test]
    public function persentase_bukan_angka_ditolak(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('tidak sah');

        Money::tagihan('1000')->persen('dua belas persen');
    }

    #[Test]
    public function persentase_membulatkan_simetris_untuk_markup_dan_potongan(): void
    {
        // 0,00005 pada skala 4 membulat ke 0,0001 — dan -0,00005 ke -0,0001.
        $naik = Money::tagihan('1')->persen('0.005');
        $turun = Money::tagihan('1')->persen('-0.005');

        $this->assertSame((string) $naik->negasi(), (string) $turun,
            'Markup dan potongan harus dibulatkan simetris, tidak condong ke salah satu pihak');
    }
}
