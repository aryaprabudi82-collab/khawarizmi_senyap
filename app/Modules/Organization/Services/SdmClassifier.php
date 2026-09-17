<?php

namespace App\Modules\Organization\Services;

/**
 * Menggolongkan pegawai RSUI dari berkas kepegawaian resmi.
 *
 * SUMBERNYA MEMBAWA PENGGOLONGAN RESMI, jadi tidak ada yang perlu ditebak.
 * Upaya sebelumnya memakai ekspor tenaga kesehatan sistem lama, dan di sana
 * kategori harus disimpulkan dari sebutan profesi — 6.825 baris yang dua
 * pertiganya ternyata mahasiswa dan residen, tanpa NIP maupun unit kerja.
 * Berkas ini memuat KATEGORI STAF yang ditetapkan RSUI sendiri: empat
 * golongan berkode, sudah dipakai untuk pelaporan ketenagaan.
 *
 * PEMETAAN KE KATEGORI INTERNAL:
 *
 *   1 Medis                          -> dokter
 *   2 Farmasi, Perawat, Nakes lain   -> perawat ATAU penunjang, menurut
 *                                       jabatannya; golongan ini mencampur
 *                                       456 Ners dengan 65 Asisten Apoteker,
 *                                       21 Pranata Laboratorium, dan 20
 *                                       Radiografer, dan menyamakan perawat
 *                                       dengan radiografer membuat laporan
 *                                       ketenagaan keperawatan salah hitung.
 *   3 Penunjang Pelayanan            -> penunjang
 *   4 Penunjang Non Pelayanan        -> non-medis
 */
class SdmClassifier
{
    public const DOKTER = 'dokter';

    public const PERAWAT = 'perawat';

    public const PENUNJANG = 'penunjang';

    public const NON_MEDIS = 'non-medis';

    /** Kode kategori staf RSUI. */
    private const KATEGORI_MEDIS = '1';

    private const KATEGORI_NAKES = '2';

    private const KATEGORI_PENUNJANG_YAN = '3';

    private const KATEGORI_PENUNJANG_NON_YAN = '4';

    /**
     * Kata kunci jabatan yang menandai PERAWAT di dalam kategori 2.
     *
     * Dicocokkan pada nama jabatan, bukan pada gelar — "Ners" adalah jabatan
     * 456 orang di sini, dan "Bidan" tetap tenaga keperawatan menurut
     * pengelompokan RSUI.
     */
    private const JABATAN_PERAWAT = ['ners', 'perawat', 'bidan', 'keperawatan'];

    /**
     * Kata kunci jabatan -> jenis penunjang.
     *
     * Urutannya berarti: yang lebih khusus diperiksa lebih dulu, karena
     * "Asisten Apoteker" mengandung "apoteker" dan "Staf Administrasi
     * Farmasi" mengandung "farmasi".
     *
     * @var array<string, list<string>>
     */
    private const JENIS_PENUNJANG = [
        'laboratorium' => ['pranata laboratorium', 'analis', 'laboratorium', 'patologi'],
        'radiologi' => ['radiografer', 'radiologi', 'radioterapi'],
        'farmasi' => ['apoteker', 'farmasi'],
        'gizi' => ['nutrisionis', 'gizi', 'cook', 'pramusaji', 'juru masak', 'pengolah makanan'],
        'rehab-medik' => ['fisioterapis', 'fisioterapi', 'okupasi', 'terapis wicara', 'ortotik', 'prostetis'],
        'bank-darah' => ['bank darah', 'transfusi'],
        'cssd' => ['sterilisasi', 'cssd'],
        'gigi' => ['terapis gigi', 'teknik gigi'],
        'rekam-medis' => ['rekam medis', 'koder', 'casemix'],
        'psikologi' => ['psikolog'],
        'sanitasi' => ['sanitarian', 'kesehatan lingkungan'],
        'binatu' => ['binatu', 'laundry'],
        'forensik' => ['forensik', 'kamar jenazah', 'pemulasaraan'],
    ];

    /**
     * Status praktik dokter yang berarti MASIH MELAYANI.
     *
     * Hanya nilai ini yang dianggap aktif. Status lain — apa pun bunyinya —
     * diperlakukan tidak aktif: dokter yang sudah berhenti tapi terlanjur
     * tercatat aktif akan muncul sebagai pilihan DPJP dan menerima pasien.
     */
    private const PRAKTIK_AKTIF = 'AKTIF';

    /**
     * Menggolongkan seorang pegawai dari lembar kepegawaian.
     *
     * @return array{category: string, support_type: ?string}
     */
    public function golongkanPegawai(string $kodeKategori, string $jabatan): array
    {
        $kode = trim($kodeKategori);
        $jab = mb_strtolower(trim($jabatan));

        if ($kode === self::KATEGORI_MEDIS) {
            return ['category' => self::DOKTER, 'support_type' => null];
        }

        if ($kode === self::KATEGORI_NAKES) {
            foreach (self::JABATAN_PERAWAT as $kunci) {
                if (str_contains($jab, $kunci)) {
                    return ['category' => self::PERAWAT, 'support_type' => null];
                }
            }

            return ['category' => self::PENUNJANG, 'support_type' => $this->jenisPenunjang($jab)];
        }

        if ($kode === self::KATEGORI_PENUNJANG_YAN) {
            return ['category' => self::PENUNJANG, 'support_type' => $this->jenisPenunjang($jab)];
        }

        if ($kode === self::KATEGORI_PENUNJANG_NON_YAN) {
            return ['category' => self::NON_MEDIS, 'support_type' => null];
        }

        /*
         * Kode di luar 1-4 TIDAK ditebak dari jabatannya. Kategori staf
         * ditetapkan RSUI; menebaknya di sini berarti menerbitkan
         * penggolongan yang tidak pernah mereka putuskan.
         */
        return ['category' => self::NON_MEDIS, 'support_type' => null];
    }

    /** Jenis penunjang dari nama jabatan, atau null bila tidak dikenali. */
    public function jenisPenunjang(string $jabatan): ?string
    {
        $j = mb_strtolower(trim($jabatan));

        foreach (self::JENIS_PENUNJANG as $jenis => $kunci) {
            foreach ($kunci as $k) {
                if (str_contains($j, $k)) {
                    return $jenis;
                }
            }
        }

        return null;
    }

    /**
     * Apakah dokter ini boleh menjadi DPJP?
     *
     * DOKTER MITRA IKUT BOLEH. Mereka bukan pegawai tetap RSUI, tapi memang
     * memegang pasien dan menandatangani rekam medis — status kemitraan
     * urusan kepegawaian, bukan kewenangan klinis. Yang menentukan di sini
     * hanya apakah praktiknya masih aktif.
     */
    public function bolehJadiDpjp(string $statusPraktik): bool
    {
        return strtoupper(trim($statusPraktik)) === self::PRAKTIK_AKTIF;
    }

    /**
     * Gelar depan dari "Nama Lengkap + Gelar".
     *
     * Lembar ini menyediakan nama bersih pada kolom tersendiri, jadi yang
     * dibutuhkan hanya gelarnya — tidak perlu memotong apa pun dari nama.
     */
    public function gelarDepan(string $namaBergelar): ?string
    {
        if (preg_match('/^\s*(dr|drg|apt|ns|prof)\.?\s/iu', $namaBergelar, $cocok)) {
            return ucfirst(strtolower($cocok[1])).'.';
        }

        return null;
    }

    /**
     * Spesialisasi dari "Penyebutan Dokter".
     *
     * "Dokter Umum" bukan spesialisasi — menyimpannya membuat 55 dokter
     * berspesialisasi "Umum", kolom yang tidak membedakan siapa pun.
     */
    public function spesialisasiDokter(string $penyebutan): ?string
    {
        $p = trim($penyebutan);

        if ($p === '' || $p === '-') {
            return null;
        }

        if (in_array(mb_strtolower($p), ['dokter umum', 'dokter gigi umum'], true)) {
            return null;
        }

        // "Dokter Spesialis Anak" -> "Spesialis Anak"; awalan "Dokter" tidak
        // menambah keterangan apa pun di kolom spesialisasi.
        return trim(preg_replace('/^Dokter\s+/iu', '', $p) ?? $p);
    }

    /**
     * Kunci pencocokan nama antar sumber.
     *
     * Membuang gelar dan tanda baca supaya "Ns. Budi Santoso, S.Kep" dan
     * "BUDI SANTOSO" menghasilkan kunci yang sama.
     *
     * TIDAK DAPAT DIANDALKAN SENDIRIAN, dan itu disengaja diakui: pada data
     * nyata 11 nama cocok ke lebih dari satu praktisi, dan satu di antaranya
     * benar-benar dua orang berbeda. Kunci ini untuk MENCARI kandidat, bukan
     * untuk memutuskan.
     */
    public function kunciNama(string $nama): string
    {
        $s = mb_strtolower($nama);
        $s = preg_replace('/\b(dr|drg|apt|ns|prof|sp|s|m|a|md|kep|farm|si|mars|mkm|ph|d)\.?\b/u', ' ', $s) ?? $s;
        $s = preg_replace('/[^a-z ]/u', ' ', $s) ?? $s;

        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }
}
