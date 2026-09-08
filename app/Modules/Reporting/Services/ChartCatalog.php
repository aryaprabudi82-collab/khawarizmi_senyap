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

    /**
     * Dua macam dataset, dan bedanya bukan soal rasa.
     *
     * PERISTIWA dihitung dalam sebuah periode: kunjungan, insiden,
     * pengajuan. Pertanyaannya "berapa banyak yang terjadi bulan lalu".
     *
     * KONDISI adalah keadaan saat ini: berapa aset di tiap ruang, berapa
     * di tiap kategori. Pertanyaannya "berapa banyak yang ADA", dan
     * menyaringnya dengan periode akan menjawab pertanyaan yang berbeda
     * — "berapa aset yang DIPEROLEH bulan lalu" — dengan judul yang
     * sama. Angka yang masuk akal dan salah adalah yang paling
     * berbahaya, jadi kedua macam ini dibedakan dan penyaring periode
     * pada dataset kondisi harus disebut sendiri.
     */
    public const PERISTIWA = 'peristiwa';

    public const KONDISI = 'kondisi';

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

            'insiden-keselamatan' => [
                'label' => 'Insiden keselamatan pasien (IKP)',
                'source' => 'quality.v_incident_summary',
                'date_column' => 'occurred_at',
                'khanza' => 'Menaungi grafik_ikp_pertahun, _perbulan, _pertanggal, _jenis, _dampak',
                'dimensions' => [
                    'jenis' => ['column' => 'r.incident_type', 'label' => 'Jenis insiden (KPC/KNC/KTC/KTD/sentinel)'],
                    'dampak' => ['column' => 'r.severity_band', 'label' => 'Pita dampak (biru/hijau/kuning/merah)'],
                    'status' => ['column' => 'r.status', 'label' => 'Status penanganan'],
                    'lokasi' => ['column' => 'r.location_detail', 'label' => 'Lokasi rinci'],
                ],
            ],

            'k3' => [
                'label' => 'Insiden keselamatan & kesehatan kerja (K3)',
                'source' => 'quality.v_k3_incident',
                'date_column' => 'occurred_at',
                'khanza' => 'Menaungi 10 kode grafik_k3_*',
                'dimensions' => [
                    'jenis-cidera' => ['column' => 'r.injury_type', 'label' => 'Jenis cidera'],
                    // Berbeda dari jenis cidera, dan itulah alasan
                    // kolomnya ditambahkan — lihat catatan migrasi
                    // publish_quality_chart_contracts.
                    'jenis-luka' => ['column' => 'r.wound_type', 'label' => 'Jenis luka'],
                    'dampak-cidera' => ['column' => 'r.injury_impact', 'label' => 'Dampak cidera'],
                    'bagian-tubuh' => ['column' => 'r.body_part', 'label' => 'Bagian tubuh'],
                    'jenis-pekerjaan' => ['column' => 'r.job_type', 'label' => 'Jenis pekerjaan'],
                    'lokasi' => ['column' => 'r.location', 'label' => 'Lokasi kejadian'],
                    'penyebab' => ['column' => 'r.cause', 'label' => 'Penyebab kecelakaan'],
                    'status' => ['column' => 'r.status', 'label' => 'Status penanganan'],
                ],
            ],

            'inventaris' => [
                'label' => 'Inventaris & aset',
                'kind' => self::KONDISI,
                'source' => 'asset.v_asset_inventory',
                'date_column' => 'acquisition_date',
                'khanza' => 'Menaungi 5 kode grafik_inventaris_*',
                'dimensions' => [
                    'ruang' => ['column' => 'r.location_name', 'label' => 'Ruang / lokasi'],
                    'jenis' => ['column' => 'r.type_name', 'label' => 'Jenis'],
                    'kategori' => ['column' => 'r.category_name', 'label' => 'Kategori'],
                    'merk' => ['column' => 'r.brand', 'label' => 'Merk'],
                    'produsen' => ['column' => 'r.manufacturer_name', 'label' => 'Produsen'],
                    'kondisi' => ['column' => 'r.condition', 'label' => 'Kondisi'],
                    'status' => ['column' => 'r.status', 'label' => 'Status aset'],
                ],
            ],

            'pengajuan-aset' => [
                'label' => 'Pengajuan aset',
                'source' => 'asset.v_asset_requisition',
                'date_column' => 'created_at',
                'khanza' => 'Menaungi grafik_pengajuan_aset_urgensi, _status, _departemen',
                'dimensions' => [
                    'urgensi' => ['column' => 'r.urgency', 'label' => 'Urgensi'],
                    'status' => ['column' => 'r.status', 'label' => 'Status pengajuan'],
                    'departemen' => ['column' => 'r.unit_name', 'label' => 'Unit / departemen'],
                ],
            ],

            'perbaikan-inventaris' => [
                'label' => 'Perbaikan inventaris',
                'source' => 'asset.v_maintenance_request',
                'date_column' => 'created_at',
                'khanza' => 'Menaungi 4 kode grafik_perbaikan_inventaris_*',
                'joins' => [
                    [
                        'table' => 'platform.v_user_summary',
                        'alias' => 'u',
                        'local' => 'r.assigned_to',
                        'foreign' => 'u.id',
                    ],
                ],
                'dimensions' => [
                    'status' => ['column' => 'r.status', 'label' => 'Status perbaikan'],
                    'pelaksana' => ['column' => 'u.name', 'label' => 'Pelaksana'],
                    'lokasi' => ['column' => 'r.location_name', 'label' => 'Lokasi aset'],
                ],
            ],

            'kesling' => [
                'label' => 'Pemakaian air & timbulan limbah',
                'source' => 'asset.v_environmental_measurement',
                'date_column' => 'measured_on',
                // DIJUMLAHKAN, bukan dihitung — lihat catatan kelas
                // ChartService: grafik yang menghitung baris akan
                // menampilkan "jumlah pencatatan" berlabel "pemakaian
                // air".
                'measure' => 'r.quantity',
                'measure_label' => 'Jumlah (sesuai satuan kategorinya)',
                'khanza' => 'Menaungi 10 kode grafik air PDAM/tanah dan limbah B3/domestik',
                'dimensions' => [
                    'kategori' => ['column' => 'r.category', 'label' => 'Kategori pengukuran'],
                    'parameter' => ['column' => 'r.parameter', 'label' => 'Parameter'],
                    'satuan' => ['column' => 'r.unit', 'label' => 'Satuan'],
                ],
            ],

            'pegawai' => [
                'label' => 'Kepegawaian',
                'kind' => self::KONDISI,
                'source' => 'hr.v_employee_summary',
                'date_column' => 'id',
                'khanza' => 'Menaungi 3 dari 9 kode grafik pegawai; 5 sisanya di pendingData()',
                'dimensions' => [
                    'jabatan' => ['column' => 'r.position', 'label' => 'Jabatan'],
                    'pendidikan' => ['column' => 'r.education', 'label' => 'Pendidikan terakhir'],
                    'status-kerja' => ['column' => 'r.employment_type', 'label' => 'Status kerja'],
                ],
            ],

            'obat' => [
                'label' => 'Item apotek',
                'kind' => self::KONDISI,
                'source' => 'pharmacy.v_drug_catalog',
                'date_column' => 'drug_id',
                'khanza' => 'Menaungi item_apotek_jenis, _kategori, _golongan, _industrifarmasi',
                'dimensions' => [
                    'jenis' => ['column' => 'r.form', 'label' => 'Bentuk sediaan'],
                    'kategori' => ['column' => 'r.category_name', 'label' => 'Kategori'],
                    'golongan' => ['column' => 'r.class_name', 'label' => 'Golongan'],
                    'industri-farmasi' => ['column' => 'r.manufacturer_name', 'label' => 'Industri farmasi'],
                ],
            ],

            'diet' => [
                'label' => 'Order diet pasien',
                'source' => 'inpatient.v_diet_order',
                'date_column' => 'start_date',
                'khanza' => 'Menaungi 4 kode grafik_porsidiet_*',
                'dimensions' => [
                    'jenis-diet' => ['column' => 'r.diet_type', 'label' => 'Jenis diet'],
                    'bangsal' => ['column' => 'r.ward_name', 'label' => 'Bangsal'],
                    'kelas' => ['column' => 'r.room_class', 'label' => 'Kelas ruang'],
                    'status' => ['column' => 'r.status', 'label' => 'Status order'],
                ],
            ],

            'hemodialisa' => [
                'label' => 'Sesi hemodialisa',
                'source' => 'clinical.v_dialysis_session',
                'date_column' => 'started_at',
                'khanza' => 'Menaungi grafik_harian_hemodialisa, _bulanan, _tahunan',
                'dimensions' => [
                    'akses' => ['column' => 'r.access_type', 'label' => 'Jenis akses vaskular'],
                    'mesin' => ['column' => 'r.machine_code', 'label' => 'Mesin'],
                    'status' => ['column' => 'r.status', 'label' => 'Status sesi'],
                ],
            ],

            'ranap-pulang' => [
                'label' => 'Kepulangan rawat inap',
                'source' => 'inpatient.v_admission_summary',
                'date_column' => 'discharged_at',
                'khanza' => 'Menaungi grafik_sttspulangranap dan grafik_bulanan_meninggal',
                'dimensions' => [
                    'status-pulang' => ['column' => 'r.discharge_status', 'label' => 'Status pulang'],
                    'bangsal' => ['column' => 'r.room_unit_name', 'label' => 'Bangsal'],
                    'kelas' => ['column' => 'r.room_class', 'label' => 'Kelas ruang'],
                    'dpjp' => ['column' => 'r.dpjp_name', 'label' => 'DPJP'],
                ],
            ],
        ];
    }

    /**
     * Kode grafik Khanza yang BELUM BISA DIGAMBAR karena datanya memang
     * belum ada — bukan karena terlewat.
     *
     * Disebutkan terang-terangan supaya terbaca sebagai keputusan.
     * Kelima dimensi kepegawaian di bawah ini menuntut RSP UI
     * menetapkan kosakatanya sendiri: berapa jenjang jabatan, apa
     * namanya, siapa masuk kelompok mana, bagaimana risiko kerja
     * digolongkan. Membuat kolomnya sekarang berarti menebak struktur
     * kepegawaian sebuah rumah sakit demi menggambar batang, dan yang
     * dihasilkan grafik yang tampak resmi dengan kategori yang tidak
     * pernah disepakati siapa pun — kekeliruan yang sama seperti
     * mengarang isi formulir klinis pada domain M.
     *
     * @return array<string, string>
     */
    public static function pendingData(): array
    {
        return [
            'grafik_jenjang_jabatanpegawai' => 'Menunggu RSP UI menetapkan daftar jenjang jabatan.',
            'grafik_kelompok_jabatanpegawai' => 'Menunggu RSP UI menetapkan pengelompokan jabatan.',
            'grafik_sttswppegawai' => 'Menunggu data status wajib pajak, yang tinggal di penggajian.',
            'grafik_resiko_kerjapegawai' => 'Menunggu RSP UI menetapkan penggolongan risiko kerja.',
            'grafik_emergency_indexpegawai' => 'Menunggu RSP UI menetapkan indeks kesiapsiagaan bencana.',
            'grafik_bidangpegawai' => 'Bidang/bagian belum dibedakan dari unit kerja pada hr.employees.',
            'grafik_departemenpegawai' => 'Departemen belum dibedakan dari unit kerja pada hr.employees.',
        ];
    }

    /**
     * Kode grafik Khanza yang SENGAJA tidak dibuatkan dataset di sini
     * karena sudah dihitung konteks lain.
     *
     * Disebutkan terang-terangan, bukan dibiarkan tampak terlewat:
     * kesembilan kode HAIs sudah dilayani HaisSurveillanceService sejak
     * domain J item E, dan ratesByUnit($dari, $sampai, 'vap') PERSIS
     * grafik_HAIs_laju_vap. Menghitung ulang lajunya di sini melahirkan
     * sumber kedua bagi angka infeksi — dan dua angka laju yang berbeda
     * untuk bangsal yang sama jauh lebih buruk daripada satu grafik yang
     * harus dibuka di layar lain.
     *
     * @return array<string, string>
     */
    public static function servedElsewhere(): array
    {
        return [
            'grafik_HAIs_pasienbangsal' => 'HaisSurveillanceService::events() per bangsal',
            'grafik_HAIs_pasienbulan' => 'HaisSurveillanceService::monthlyEvents()',
            'grafik_HAIs_laju_vap' => "HaisSurveillanceService::ratesByUnit(..., 'vap')",
            'grafik_HAIs_laju_iad' => "HaisSurveillanceService::ratesByUnit(..., 'iad')",
            'grafik_HAIs_laju_pleb' => "HaisSurveillanceService::ratesByUnit(..., 'plebitis')",
            'grafik_HAIs_laju_isk' => "HaisSurveillanceService::ratesByUnit(..., 'isk')",
            'grafik_HAIs_laju_ilo' => "HaisSurveillanceService::ratesByUnit(..., 'ilo')",
            'grafik_HAIs_laju_hap' => "HaisSurveillanceService::ratesByUnit(..., 'hap')",
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
