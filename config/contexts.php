<?php

/*
|--------------------------------------------------------------------------
| Manifes Bounded Context
|--------------------------------------------------------------------------
|
| Satu-satunya sumber kebenaran soal batas antar-modul. Setiap konteks
| memiliki satu schema PostgreSQL dan hanya boleh disentuh lewat service-nya.
|
| ATURAN YANG DITEGAKKAN OLEH tests/Architecture/ContextBoundaryTest.php
|
|   1. Migrasi sebuah modul hanya boleh membuat tabel di schema miliknya.
|   2. Tidak ada foreign key yang menyeberang schema.
|   3. Query aplikasi hanya boleh menyentuh tabel schema sendiri, atau
|      view yang secara sengaja diterbitkan konteks lain lewat 'publishes'.
|
| Menambah entri di 'publishes' adalah keputusan desain, bukan jalan pintas:
| view yang diterbitkan menjadi kontrak publik konteks tersebut dan tidak
| boleh berubah bentuk tanpa menyesuaikan seluruh konsumennya.
|
*/

return [

    /*
    | Konteks yang sudah digarap. Urutan menyusul ketergantungannya.
    */
    'active' => [

        'platform' => [
            'schema'      => 'platform',
            'module'      => 'Platform',
            'description' => 'Pengguna, peran, katalog permission, sesi, dan jejak audit.',
            'domains'     => ['U'],
            'publishes'   => [
                'v_user_summary' => 'id, username, nip, nama, aktif — untuk kolom "dicatat oleh" di konteks lain.',
            ],
        ],

        'catalog' => [
            'schema'      => 'catalog',
            'module'      => 'Catalog',
            'description' => 'Layanan, tarif, dan penjamin. Data referensi yang dihargai dan ditagihkan.',
            'domains'     => ['K'],
            'publishes'   => [],
        ],

        'organization' => [
            'schema'      => 'organization',
            'module'      => 'Organization',
            'description' => 'Unit layanan, poliklinik, ruang, bangsal, dan praktisi (SIP, spesialisasi, periode aktif).',
            'domains'     => ['U', 'C'],
            'publishes'   => [
                'v_unit_summary' => 'Unit layanan aktif berikut kuota hariannya.',
                'v_practitioner_summary' => 'Praktisi berikut spesialisasi dan masa aktifnya.',
            ],
        ],

        'identity' => [
            'schema'      => 'identity',
            'module'      => 'Identity',
            'description' => 'Master pasien, penomoran rekam medis, resolusi identitas dan deduplikasi.',
            'domains'     => ['M'],
            'publishes'   => [
                'v_patient_summary' => 'Identitas ringkas pasien berikut penanda perhatian khusus.',
            ],
        ],

        'encounter' => [
            'schema'      => 'encounter',
            'module'      => 'Encounter',
            'description' => 'Registrasi, booking, antrean, dan penetapan DPJP.',
            'domains'     => ['A'],
            'publishes'   => [
                'v_registration_summary' => 'Kunjungan aktif berikut pasien, unit, dokter, dan penjaminnya. '
                    . 'Dipakai clinical, order, pharmacy, dan billing sebagai konteks kunjungan.',
            ],
        ],

        'clinical' => [
            'schema'      => 'clinical',
            'module'      => 'Clinical',
            'description' => 'Rekam medis elektronik: asesmen, SOAP, tanda vital, diagnosis, dan alergi. '
                . 'Mengacu Permenkes 24/2022.',
            'domains'     => ['M'],
            'publishes'   => [
                'v_patient_allergy' => 'Alergi aktif per pasien. Dipakai pharmacy untuk telaah resep.',
                'v_encounter_diagnosis' => 'Diagnosis per kunjungan berikut kode ICD-10. '
                    . 'Dipakai billing untuk pengajuan klaim.',
            ],
        ],

    ],

    /*
    | Konteks yang sudah direncanakan tapi belum digarap. Didaftarkan di sini
    | supaya batasnya dipikirkan sejak awal, bukan ditemukan saat kepepet.
    */
    'planned' => [
        'order'          => ['schema' => 'orders',         'domains' => ['M', 'B'], 'description' => 'Siklus permintaan penunjang: order, sampel, hasil. Lab dan radiologi.'],
        'pharmacy'       => ['schema' => 'pharmacy',       'domains' => ['D'],      'description' => 'Resep, telaah, penyerahan, stok dengan batch dan kedaluwarsa.'],
        'billing'        => ['schema' => 'billing',        'domains' => ['I'],      'description' => 'Charge, tagihan, deposit, piutang, pembayaran.'],
        'finance'        => ['schema' => 'finance',        'domains' => ['K'],      'description' => 'Akun, jurnal, buku besar, arus kas.'],
        'integration'    => ['schema' => 'integration',    'domains' => ['L'],      'description' => 'Adapter BPJS, SATUSEHAT, INACBG, LIS, PACS. Tabel pemetaan dan ledger pengiriman.'],
        'reporting'      => ['schema' => 'reporting',      'domains' => ['J', 'O'], 'description' => 'Read model untuk laporan regulasi dan dashboard manajemen.'],
        'hr'             => ['schema' => 'hr',             'domains' => ['C'],      'description' => 'Pegawai, presensi, jadwal, penggajian.'],
        'inventory'      => ['schema' => 'inventory',      'domains' => ['E'],      'description' => 'Barang non-medis dan penunjang.'],
        'asset'          => ['schema' => 'asset',          'domains' => ['G'],      'description' => 'Aset, inventaris, CSSD, pemeliharaan, kesehatan lingkungan.'],
        'blood'          => ['schema' => 'blood',          'domains' => ['N'],      'description' => 'Unit transfusi darah.'],
        'correspondence' => ['schema' => 'correspondence', 'domains' => ['P'],      'description' => 'Surat masuk, surat keluar, pengumuman e-pasien.'],
        'quality'        => ['schema' => 'quality',        'domains' => ['R'],      'description' => 'PCRA, ICRA, dan pengendalian risiko.'],
    ],

    /*
    | Schema di luar bounded context. Ini milik framework, bukan domain,
    | jadi boleh disentuh siapa saja.
    */
    'shared_schema' => 'public',

    'framework_tables' => [
        'migrations', 'sessions', 'cache', 'cache_locks',
        'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens',
    ],

];
