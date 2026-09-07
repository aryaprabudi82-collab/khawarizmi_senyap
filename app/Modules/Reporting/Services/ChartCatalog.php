<?php

namespace App\Modules\Reporting\Services;

/**
 * Katalog dataset & sumbu grafik (domain O item A).
 *
 * DOMAIN O BERISI 113 KODE, DAN SELURUHNYA GRAFIK. Begitu dikelompokkan,
 * polanya terlihat: grafik_kunjungan_poli dan grafik_kunjungan_perdokter
 * adalah kueri yang SAMA dengan GROUP BY berbeda; _pertahun, _perbulan,
 * dan _pertanggal adalah kueri yang sama dengan satuan waktu berbeda.
 * Membuat 113 layar berarti menulis kueri yang sama berpuluh kali, dan
 * memperbaiki satu kesalahan di dalamnya berarti memperbaikinya berpuluh
 * kali pula.
 *
 * MAKA SUMBU GRAFIK JADI DATA, BUKAN KODE — aturan yang sama seperti
 * panel observasi pada domain M item D, yang membuat 12 kode
 * catatan_observasi_* tidak menjadi 12 tabel. Menambah satu grafik baru
 * di sini cukup satu baris pada katalog ini, tanpa migrasi dan tanpa
 * layar baru.
 *
 * TIAP DATASET MENYEBUT SENDIRI SUMBU YANG SAH BAGINYA. Yang tidak
 * disebut ditolak — bukan diabaikan diam-diam. Grafik yang
 * mengelompokkan menurut kolom yang tidak ada akan tampil kosong dan
 * dibaca sebagai "tidak ada kejadian", padahal yang terjadi kuerinya
 * salah.
 *
 * KOLOM SUMBU TIDAK PERNAH DATANG DARI PEMANGGIL. Yang diterima
 * pemanggil hanya KUNCI sumbu; kolom SQL-nya dicari di katalog ini.
 * Menerima nama kolom dari luar berarti membiarkan penyerang memilih
 * kolom mana pun dari view yang diterbitkan.
 */
class ChartCatalog
{
    public const HARIAN = 'harian';

    public const BULANAN = 'bulanan';

    public const TAHUNAN = 'tahunan';

    /** Satuan waktu berikut format pengelompokannya di PostgreSQL. */
    public const GRANULARITAS = [
        self::HARIAN => ['trunc' => 'day', 'label' => 'Per tanggal'],
        self::BULANAN => ['trunc' => 'month', 'label' => 'Per bulan'],
        self::TAHUNAN => ['trunc' => 'year', 'label' => 'Per tahun'],
    ];

    /**
     * Dataset yang bisa digrafikkan.
     *
     * Tiap dataset menyebut: sumbernya (view yang DITERBITKAN konteks
     * lain, bukan tabel), kolom tanggalnya, sumbu yang sah, dan
     * penyaring bawaan yang selalu berlaku.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function datasets(): array
    {
        return [
            'kunjungan' => [
                'label' => 'Registrasi & kunjungan',
                'source' => 'encounter.v_registration_summary',
                'date_column' => 'service_date',
                'patient_join' => 'patient_id',
                'khanza' => 'Menaungi ~25 kode grafik_kunjungan_*, grafik_lab_ralan*, grafik_rad_ralan*',
                'dimensions' => [
                    'unit' => ['column' => 'r.unit_name', 'label' => 'Poliklinik / unit'],
                    'dokter' => ['column' => 'r.practitioner_name', 'label' => 'Dokter'],
                    'penjamin' => ['column' => 'r.payer_name', 'label' => 'Cara bayar'],
                    'jenis-rawat' => ['column' => 'r.care_type', 'label' => 'Jenis perawatan'],
                    'status' => ['column' => 'r.status', 'label' => 'Status kunjungan'],
                    'jenis-kelamin' => ['column' => 'p.sex', 'label' => 'Jenis kelamin'],
                    'agama' => ['column' => 'p.religion', 'label' => 'Agama'],
                    'pekerjaan' => ['column' => 'p.occupation', 'label' => 'Pekerjaan'],
                    'pendidikan' => ['column' => 'p.education', 'label' => 'Pendidikan'],
                    'status-kawin' => ['column' => 'p.marital_status', 'label' => 'Status perkawinan'],
                    'suku' => ['column' => 'p.ethnicity', 'label' => 'Suku bangsa'],
                    'bahasa' => ['column' => 'p.language', 'label' => 'Bahasa'],
                    'kota' => ['column' => 'p.city_name', 'label' => 'Kota / kabupaten'],
                    'kecamatan' => ['column' => 'p.district_name', 'label' => 'Kecamatan'],
                    'kelompok-umur' => ['column' => self::EKSPRESI_UMUR, 'label' => 'Kelompok umur'],
                ],
            ],
        ];
    }

    /**
     * Kelompok umur, DIHITUNG dari tanggal lahir terhadap tanggal
     * pelayanan — bukan terhadap hari ini.
     *
     * Perbedaannya nyata: kunjungan seorang bayi tiga tahun lalu harus
     * tetap terhitung sebagai kunjungan bayi, dan menghitung umurnya
     * terhadap hari ini akan memindahkannya ke kelompok balita setiap
     * kali laporan yang sama dijalankan ulang.
     *
     * Batas kelompoknya mengikuti pengelompokan yang lazim dipakai
     * laporan kunjungan rumah sakit di Indonesia.
     */
    private const EKSPRESI_UMUR = "CASE
        WHEN p.birth_date IS NULL THEN 'tidak diketahui'
        WHEN r.service_date < p.birth_date THEN 'tidak diketahui'
        WHEN r.service_date < p.birth_date + INTERVAL '28 days' THEN '0-28 hari'
        WHEN r.service_date < p.birth_date + INTERVAL '1 year' THEN '29 hari - 1 tahun'
        WHEN r.service_date < p.birth_date + INTERVAL '5 years' THEN '1-4 tahun'
        WHEN r.service_date < p.birth_date + INTERVAL '15 years' THEN '5-14 tahun'
        WHEN r.service_date < p.birth_date + INTERVAL '25 years' THEN '15-24 tahun'
        WHEN r.service_date < p.birth_date + INTERVAL '45 years' THEN '25-44 tahun'
        WHEN r.service_date < p.birth_date + INTERVAL '60 years' THEN '45-59 tahun'
        ELSE '60 tahun ke atas'
    END";

    /**
     * @return array<string, mixed>|null
     */
    public static function dataset(string $key): ?array
    {
        return self::datasets()[$key] ?? null;
    }

    /**
     * Sumbu yang sah untuk sebuah dataset.
     *
     * @return array<string, string>
     */
    public static function dimensionsFor(string $dataset): array
    {
        $isi = self::dataset($dataset);

        if ($isi === null) {
            return [];
        }

        return array_map(fn ($d) => $d['label'], $isi['dimensions']);
    }
}
