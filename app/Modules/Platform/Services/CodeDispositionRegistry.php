<?php

namespace App\Modules\Platform\Services;

use RuntimeException;

/**
 * Disposisi tiap kode Khanza: dibangun, dinaungi, dilayani di tempat lain,
 * ditolak, atau BELUM DIVERIFIKASI.
 *
 * MENGAPA ARTEFAK INI ADA. Katalog Khanza berisi 1.183 access flag, dan
 * hanya 158 di antaranya menggerbangi layar tersendiri di sistem ini. Sisanya
 * dinaungi umbrella-gate, dikerjakan di konteks lain, atau sengaja ditolak.
 * Selama ini penjelasan itu hidup sebagai PROSA — di pesan commit dan di
 * tracker HTML.
 *
 * Prosa tidak bisa diperiksa. Pertanyaan "apakah domain A sampai U benar-benar
 * selesai" karena itu hanya bisa dijawab dengan membaca ulang belasan pesan
 * commit dan mempercayai penulisnya. Registri ini mengubahnya jadi data yang
 * bisa dihitung mesin, dan yang paling penting: <b>kekosongannya ikut
 * terhitung</b>.
 *
 * STATUS 'belum-diverifikasi' DISENGAJA ADA, dan jumlahnya sengaja terlihat.
 * Registri yang memaksa setiap kode punya jawaban akan mendorong orang
 * mengarang jawaban demi menutup daftar — dan daftar yang lengkap tapi
 * sebagiannya karangan lebih buruk daripada daftar yang jujur menunjukkan
 * sisa pekerjaannya. Yang dijaga uji bukan "semuanya sudah terverifikasi",
 * melainkan "tidak ada kode yang hilang dari registri, dan angka utangnya
 * tidak naik diam-diam".
 */
class CodeDispositionRegistry
{
    /** Punya gerbang & layarnya sendiri. */
    public const BERGERBANG = 'bergerbang';

    /** Dinaungi gerbang lain di area fungsional yang sama. */
    public const UMBRELLA = 'umbrella';

    /** Dikerjakan di konteks lain, bukan di konteks yang ditandai katalog. */
    public const KONTEKS_LAIN = 'konteks-lain';

    /** Sengaja tidak dibangun, dengan alasan tertulis yang bisa diperiksa. */
    public const DITOLAK = 'ditolak';

    /** Menunggu keputusan RSP UI — bukan keputusan yang boleh diambil sistem. */
    public const PERLU_KEPUTUSAN = 'perlu-keputusan-rsp-ui';

    /**
     * Sudah diperiksa, dan hasilnya: BELUM DIBANGUN.
     *
     * Beda tegas dari BELUM (belum diperiksa). Yang ini sudah ditelusuri,
     * mekanismenya mungkin ada, tapi bagian yang membuatnya bisa dipakai
     * belum ada — dan RSP UI pun tidak bisa melengkapinya sendiri karena
     * layarnya tidak dibangun. Ini pekerjaan, bukan keputusan.
     */
    public const BELUM_DIBANGUN = 'belum-dibangun';

    /** Belum diperiksa satu per satu. Inilah utang yang dihitung. */
    public const BELUM = 'belum-diverifikasi';

    public const SEMUA_STATUS = [
        self::BERGERBANG, self::UMBRELLA, self::KONTEKS_LAIN,
        self::DITOLAK, self::PERLU_KEPUTUSAN, self::BELUM_DIBANGUN, self::BELUM,
    ];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $registri = null;

    public function path(): string
    {
        return database_path('data/code-disposition.json');
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        if ($this->registri !== null) {
            return $this->registri;
        }

        $path = $this->path();

        if (! is_file($path)) {
            throw new RuntimeException('Registri disposisi kode tidak ditemukan: '.$path);
        }

        return $this->registri = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, int> */
    public function tally(): array
    {
        $hitung = array_fill_keys(self::SEMUA_STATUS, 0);

        foreach ($this->all() as $butir) {
            $hitung[$butir['disposisi']] = ($hitung[$butir['disposisi']] ?? 0) + 1;
        }

        return $hitung;
    }

    /**
     * Kode yang belum diperiksa satu per satu, dikelompokkan per domain.
     *
     * @return array<string, int>
     */
    public function unverifiedByDomain(): array
    {
        $per = [];

        foreach ($this->all() as $butir) {
            if ($butir['disposisi'] === self::BELUM) {
                $per[$butir['domain']] = ($per[$butir['domain']] ?? 0) + 1;
            }
        }

        ksort($per);

        return $per;
    }

    public function unverifiedCount(): int
    {
        return $this->tally()[self::BELUM];
    }
}
