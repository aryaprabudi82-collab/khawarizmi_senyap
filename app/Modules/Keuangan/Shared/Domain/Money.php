<?php

namespace App\Modules\Keuangan\Shared\Domain;

use InvalidArgumentException;

/**
 * Nilai uang — satu-satunya cara nilai rupiah berpindah di domain keuangan.
 *
 * MENGAPA VALUE OBJECT, BUKAN float ATAU string.
 * `float` tidak boleh dipakai untuk uang sama sekali: 0.1 + 0.2 tidak sama
 * dengan 0.3 pada aritmetika biner, dan selisihnya menumpuk sampai jadi
 * angka yang harus dijelaskan seseorang. `string` aman disimpan tapi tidak
 * bisa dihitung tanpa mengubahnya jadi sesuatu yang lain lebih dulu — dan
 * "sesuatu yang lain" itulah tempat kesalahan masuk.
 *
 * Kelas ini menyimpan nilai sebagai BILANGAN BULAT SATUAN TERKECIL
 * (minor unit), dengan skala yang menempel pada nilainya. Rp 1.234,56
 * berskala 2 disimpan sebagai 123456. Tidak ada pembagian yang tidak
 * disengaja, tidak ada pembulatan yang tidak diminta.
 *
 * DUA SKALA, DAN BATASNYA TEGAS (keputusan KA-1):
 *
 *   SKALA 2 — nilai yang DITAGIHKAN, DIBAYARKAN, atau DIJURNALKAN.
 *             Kalau angkanya bisa muncul di kuitansi atau di jurnal,
 *             skalanya 2.
 *
 *   SKALA 4 — hasil ALOKASI atau PEMBAGIAN yang belum jadi tagihan:
 *             alokasi biaya ABC, pembagian jasa medis, selisih kelas per
 *             hari, harga pokok rata-rata.
 *
 * Pembulatan dari 4 ke 2 terjadi TEPAT saat nilainya menjadi tagihan atau
 * jurnal — tidak lebih awal (selisihnya menumpuk), tidak lebih akhir
 * (jurnalnya jadi tidak balance).
 *
 * DUA NILAI BERSKALA BERBEDA TIDAK BISA DIJUMLAHKAN tanpa dinyatakan
 * skalanya lebih dulu. Itu bukan kerewelan: menjumlahkan hasil alokasi
 * berskala 4 langsung ke nilai tagihan berskala 2 adalah cara paling
 * halus memasukkan pecahan sen ke dalam kuitansi.
 */
final class Money
{
    /** Skala untuk nilai yang ditagihkan, dibayarkan, atau dijurnalkan. */
    public const SKALA_TAGIHAN = 2;

    /** Skala untuk hasil alokasi/pembagian yang belum jadi tagihan. */
    public const SKALA_ALOKASI = 4;

    private function __construct(
        public readonly int $minor,
        public readonly int $scale,
    ) {}

    // ------------------------------------------------------------- pembuatan

    /** Nilai tagihan dari angka biasa, mis. dari isian layar. */
    public static function tagihan(int|float|string $nilai): self
    {
        return self::dari($nilai, self::SKALA_TAGIHAN);
    }

    /** Nilai hasil alokasi/pembagian. */
    public static function alokasi(int|float|string $nilai): self
    {
        return self::dari($nilai, self::SKALA_ALOKASI);
    }

    public static function nol(int $scale = self::SKALA_TAGIHAN): self
    {
        return new self(0, $scale);
    }

    /**
     * Membaca nilai dari basis data (numeric PostgreSQL selalu string).
     *
     * @throws InvalidArgumentException
     */
    public static function dari(int|float|string $nilai, int $scale): self
    {
        if ($scale < 0 || $scale > 6) {
            throw new InvalidArgumentException("Skala {$scale} di luar jangkauan wajar (0-6).");
        }

        /*
         * FLOAT DITOLAK, TIDAK DIKONVERSI DIAM-DIAM. Menerimanya berarti
         * kesalahan pembulatan sudah terjadi SEBELUM nilainya sampai ke
         * sini, dan kelas ini tidak bisa memperbaikinya — hanya
         * menyembunyikannya. Yang memanggil dengan float harus
         * memperbaiki sumbernya.
         */
        if (is_float($nilai)) {
            throw new InvalidArgumentException(
                'Nilai uang tidak boleh berasal dari float — kesalahan pembulatannya sudah '
                .'terjadi sebelum sampai ke sini. Pakai string atau bilangan bulat.'
            );
        }

        $teks = trim((string) $nilai);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $teks)) {
            throw new InvalidArgumentException("Nilai uang '{$teks}' bukan angka yang sah.");
        }

        $negatif = str_starts_with($teks, '-');
        $teks = ltrim($teks, '-');

        [$bulat, $pecahan] = array_pad(explode('.', $teks, 2), 2, '');

        /*
         * Pecahan yang LEBIH PANJANG dari skalanya ditolak, bukan
         * dipotong. Memotongnya berarti membuang uang tanpa ada yang
         * memutuskannya; yang butuh pembulatan harus memintanya
         * terang-terangan lewat bulatkanKe().
         */
        if (strlen($pecahan) > $scale) {
            throw new InvalidArgumentException(
                "Nilai '{$teks}' punya {$pecahan} pecahan, melebihi skala {$scale}. "
                .'Bulatkan lebih dulu lewat bulatkanKe() supaya pembulatannya disengaja.'
            );
        }

        $minor = (int) ($bulat.str_pad($pecahan, $scale, '0'));

        return new self($negatif ? -$minor : $minor, $scale);
    }

    // ------------------------------------------------------------ aritmetika

    public function tambah(self $lain): self
    {
        $this->pastikanSkalaSama($lain, 'menjumlahkan');

        return new self($this->minor + $lain->minor, $this->scale);
    }

    public function kurang(self $lain): self
    {
        $this->pastikanSkalaSama($lain, 'mengurangkan');

        return new self($this->minor - $lain->minor, $this->scale);
    }

    /** Mengalikan dengan bilangan bulat — mis. jumlah item pada satu baris tagihan. */
    public function kali(int $pengali): self
    {
        return new self($this->minor * $pengali, $this->scale);
    }

    /**
     * Mengalikan dengan sebuah persentase — markup obat, diskon, PPN.
     *
     * PERSENNYA STRING, BUKAN FLOAT, dengan alasan yang sama dengan
     * seluruh kelas ini: `12.5` sebagai float bukan betul-betul 12,5, dan
     * selisihnya cukup untuk menggeser sen pada nominal besar.
     *
     * DIHITUNG PADA SKALA ALOKASI lalu dikembalikan pada skala ALOKASI
     * juga — pembulatannya DITUNDA, dan itu yang paling menentukan.
     * Membulatkan tiap baris ke rupiah lebih dulu lalu menjumlahkannya
     * membuat total resep sepuluh item meleset sampai sepuluh sen dari
     * hasil hitung ulang mana pun. Pemanggil yang butuh nilai tagihan
     * memanggil `sebagaiTagihan()` SEKALI, di akhir.
     *
     * @param  string  $persen  mis. '12.5' untuk 12,5%; boleh negatif untuk potongan
     *
     * @throws InvalidArgumentException bila persennya bukan angka
     */
    public function persen(string $persen): self
    {
        $persen = trim($persen);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $persen)) {
            throw new InvalidArgumentException(
                "Persentase '{$persen}' tidak sah — harus angka desimal, mis. '12.5'."
            );
        }

        [$bulat, $pecahan] = array_pad(explode('.', ltrim($persen, '-')), 2, '');

        /*
         * Persen diubah jadi bilangan bulat berskala supaya perkaliannya
         * seluruhnya bilangan bulat: 12.5% jadi 125 pada skala 1.
         */
        $skalaPersen = strlen($pecahan);
        $persenMinor = (int) ($bulat.$pecahan);

        if (str_starts_with($persen, '-')) {
            $persenMinor = -$persenMinor;
        }

        $dasar = $this->bulatkanKe(self::SKALA_ALOKASI);

        /*
         * nilai x persen / (100 x 10^skalaPersen). Pembagiannya memakai
         * pembulatan yang sama dengan bulatkanKe() — round-half-up pada
         * nilai absolutnya — supaya markup dan potongan diperlakukan
         * simetris, tidak condong ke salah satu pihak.
         */
        $pembilang = $dasar->minor * $persenMinor;
        $penyebut = 100 * 10 ** $skalaPersen;

        $negatif = $pembilang < 0;
        $nilai = abs($pembilang);
        $hasil = intdiv($nilai, $penyebut);

        if (($nilai % $penyebut) * 2 >= $penyebut) {
            $hasil++;
        }

        return new self($negatif ? -$hasil : $hasil, self::SKALA_ALOKASI);
    }

    public function negasi(): self
    {
        return new self(-$this->minor, $this->scale);
    }

    public function absolut(): self
    {
        return new self(abs($this->minor), $this->scale);
    }

    // ----------------------------------------------------- skala & pembulatan

    /**
     * Mengubah skala, dengan pembulatan yang DIMINTA — bukan diam-diam.
     *
     * Memakai round-half-up (pembulatan ke atas pada tepat setengah),
     * sesuai kelaziman penagihan di Indonesia. Yang perlu diingat: ini
     * SATU-SATUNYA tempat nilai uang boleh kehilangan ketelitian, dan ia
     * harus dipanggil dengan sengaja.
     */
    public function bulatkanKe(int $scale): self
    {
        if ($scale === $this->scale) {
            return $this;
        }

        if ($scale > $this->scale) {
            return new self($this->minor * 10 ** ($scale - $this->scale), $scale);
        }

        $pembagi = 10 ** ($this->scale - $scale);
        $negatif = $this->minor < 0;
        $nilai = abs($this->minor);

        $hasil = intdiv($nilai, $pembagi);

        if (($nilai % $pembagi) * 2 >= $pembagi) {
            $hasil++;
        }

        return new self($negatif ? -$hasil : $hasil, $scale);
    }

    /** Nilai ini sebagai nilai tagihan — dibulatkan bila perlu. */
    public function sebagaiTagihan(): self
    {
        return $this->bulatkanKe(self::SKALA_TAGIHAN);
    }

    // -------------------------------------------------------------- pembagian

    /**
     * Membagi nilai ini ke beberapa bagian menurut bobot, TANPA KEHILANGAN
     * SATU SEN PUN.
     *
     * INI METHOD TERPENTING DI KELAS INI. Pembagian jasa medis ke operator,
     * asisten, dan anestesi; alokasi biaya penunjang ke unit pelayanan;
     * pembagian selisih kelas per hari — semuanya lewat sini.
     *
     * Cara naif (bagi lalu bulatkan tiap bagian) menghasilkan jumlah yang
     * TIDAK SAMA dengan nilai asalnya. Rp 1.000.000 dibagi tiga sama rata
     * menjadi 333.333,33 x 3 = 999.999,99 — dan satu sen itu hilang tanpa
     * ada yang mencatatnya. Dikalikan ribuan transaksi per bulan, ia jadi
     * selisih yang harus dijelaskan seseorang.
     *
     * Di sini tiap bagian dihitung sebagai pembagian bilangan bulat, lalu
     * SISANYA dibagikan satu-satu ke bagian dengan bobot terbesar. Jumlah
     * seluruh bagian SELALU sama persis dengan nilai asalnya — dikunci uji.
     *
     * @param  array<string, int>  $bobot  Bobot per penerima; nol berarti tidak dapat
     * @return array<string, self>
     *
     * @throws InvalidArgumentException
     */
    public function bagi(array $bobot): array
    {
        if ($bobot === []) {
            throw new InvalidArgumentException('Pembagian membutuhkan sekurang-kurangnya satu penerima.');
        }

        foreach ($bobot as $kunci => $b) {
            if ($b < 0) {
                throw new InvalidArgumentException("Bobot '{$kunci}' negatif ({$b}); bobot tidak boleh negatif.");
            }
        }

        $total = array_sum($bobot);

        if ($total === 0) {
            throw new InvalidArgumentException(
                'Seluruh bobot bernilai nol, jadi tidak ada dasar pembagian. '
                .'Nilai yang tidak bisa dibagi harus ditangani pemanggil, bukan dibagi rata diam-diam.'
            );
        }

        $hasil = [];
        $terbagi = 0;

        foreach ($bobot as $kunci => $b) {
            $bagian = intdiv($this->minor * $b, $total);
            $hasil[$kunci] = $bagian;
            $terbagi += $bagian;
        }

        /*
         * SISA DIBAGIKAN KE BOBOT TERBESAR LEBIH DULU, dan urutannya
         * ditetapkan supaya hasilnya DAPAT DIREPRODUKSI. Pembagian yang
         * hasilnya berubah-ubah antar pemanggilan tidak bisa diaudit —
         * dan jasa medis wajib bisa diaudit.
         */
        $sisa = $this->minor - $terbagi;

        if ($sisa !== 0) {
            $urut = $bobot;
            arsort($urut);
            $kunci = array_keys($urut);

            $arah = $sisa > 0 ? 1 : -1;
            $sisa = abs($sisa);

            for ($i = 0; $sisa > 0; $i++, $sisa--) {
                $hasil[$kunci[$i % count($kunci)]] += $arah;
            }
        }

        return array_map(fn (int $m) => new self($m, $this->scale), $hasil);
    }

    // ------------------------------------------------------------ perbandingan

    public function samaDengan(self $lain): bool
    {
        return $this->scale === $lain->scale && $this->minor === $lain->minor;
    }

    public function lebihDari(self $lain): bool
    {
        $this->pastikanSkalaSama($lain, 'membandingkan');

        return $this->minor > $lain->minor;
    }

    public function nolKah(): bool
    {
        return $this->minor === 0;
    }

    public function negatifKah(): bool
    {
        return $this->minor < 0;
    }

    // ------------------------------------------------------------- penyajian

    /** Bentuk untuk disimpan ke kolom numeric PostgreSQL. */
    public function __toString(): string
    {
        $negatif = $this->minor < 0;
        $teks = str_pad((string) abs($this->minor), $this->scale + 1, '0', STR_PAD_LEFT);

        $bulat = substr($teks, 0, -$this->scale ?: strlen($teks));
        $pecahan = $this->scale > 0 ? substr($teks, -$this->scale) : '';

        return ($negatif ? '-' : '').$bulat.($pecahan === '' ? '' : '.'.$pecahan);
    }

    /** Bentuk untuk dibaca manusia: "Rp 1.234.567". */
    public function rupiah(): string
    {
        $tagihan = $this->sebagaiTagihan();
        $utuh = intdiv(abs($tagihan->minor), 100);
        $sen = abs($tagihan->minor) % 100;

        $teks = 'Rp '.number_format($utuh, 0, ',', '.');

        if ($sen !== 0) {
            $teks .= ','.str_pad((string) $sen, 2, '0', STR_PAD_LEFT);
        }

        return ($tagihan->minor < 0 ? '-' : '').$teks;
    }

    private function pastikanSkalaSama(self $lain, string $perbuatan): void
    {
        if ($this->scale !== $lain->scale) {
            throw new InvalidArgumentException(
                "Tidak bisa {$perbuatan} nilai berskala {$this->scale} dengan nilai berskala "
                ."{$lain->scale}. Samakan skalanya lebih dulu lewat bulatkanKe() supaya "
                .'perubahan ketelitiannya disengaja, bukan terjadi diam-diam.'
            );
        }
    }
}
