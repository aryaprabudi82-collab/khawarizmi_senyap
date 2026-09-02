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
            'publishes'   => [
                'v_payer_summary' => 'Penjamin aktif berikut kind-nya (umum, bpjs, asuransi, perusahaan). '
                    . 'Dipakai billing untuk menentukan siapa yang menanggung tagihan.',
            ],
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
                'v_patient_summary' => 'Identitas pasien berikut alamat terstruktur dan penanda perhatian khusus. '
                    . 'Alamat/wilayah dipakai integration untuk menyusun resource Patient SATUSEHAT.',
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

        'pharmacy' => [
            'schema'      => 'pharmacy',
            'module'      => 'Pharmacy',
            'description' => 'Resep, telaah apoteker, penyerahan, dan stok dengan batch serta kedaluwarsa.',
            'domains'     => ['D'],
            'publishes'   => [
                'v_prescription_charge' => 'Obat yang sudah diserahkan berikut nilainya. Dipakai billing untuk menarik biaya obat ke tagihan kunjungan.',
            ],
        ],

        'billing' => [
            'schema'      => 'billing',
            'module'      => 'Billing',
            'description' => 'Tagihan dan pembayaran rawat jalan. Charge line ditarik dari encounter, pharmacy, dan order.',
            'domains'     => ['I'],
            'publishes'   => [
                'v_settled_invoice' => 'Tagihan yang sudah lunas atau ditanggung penjamin. '
                    . 'Dipakai finance untuk memposting jurnal pendapatan dan membuka piutang.',
            ],
        ],

        'order' => [
            'schema'      => 'orders',
            'module'      => 'Order',
            'description' => 'Siklus permintaan penunjang lab dan radiologi pasien: order, proses, hasil, verifikasi.',
            // Domain B Khanza ("Lab Kesehatan Lingkungan") BUKAN ini - itu uji
            // air/makanan, sudah jadi konteks 'envlab' terpisah. Permission
            // tulis untuk periksa_lab/periksa_radiologi justru nyasar ke domain
            // A karena menu Khanza mencampurnya dengan registrasi, sama seperti
            // kasus resep_obat - diberikan eksplisit lewat extra_permissions
            // peran, bukan lewat context-grant.
            'domains'     => ['A'],
            'publishes'   => [
                'v_order_charge' => 'Pemeriksaan lab/radiologi yang sudah selesai berikut nilainya. '
                    . 'Dipakai billing untuk menarik biaya penunjang ke tagihan kunjungan.',
            ],
        ],

        'finance' => [
            'schema'      => 'finance',
            'module'      => 'Finance',
            'description' => 'Jurnal berpasangan dan piutang penjamin. Menutup label "ditanggung-penjamin" di '
                . 'billing menjadi kewajiban yang bisa diaudit.',
            'domains'     => ['K'],
            'publishes'   => [],
        ],

        'integration' => [
            'schema'      => 'integration',
            'module'      => 'Integration',
            'description' => 'Adapter BPJS (VClaim: eligibilitas, SEP) dan SATUSEHAT (FHIR: Patient, Encounter, '
                . 'Condition). Tabel pemetaan identitas internal-ke-eksternal dan ledger pengiriman idempoten.',
            'domains'     => ['L'],
            'publishes'   => [
                'v_bpjs_sep_status' => 'SEP yang masih berlaku (diajukan/terbit) per kunjungan. '
                    . 'Dipakai encounter/billing untuk menampilkan status BPJS tanpa menyimpan salinannya sendiri.',
            ],
        ],

        'reporting' => [
            'schema'      => 'reporting',
            'module'      => 'Reporting',
            'description' => 'Read model rekap kunjungan, frekuensi diagnosis, dan pendapatan harian rawat jalan — '
                . 'disinkronkan dari encounter/clinical/billing/catalog, bukan dibaca langsung saat laporan dibuka. '
                . 'Domain J/O Khanza (207 kapabilitas) mencakup ranap, HAIs, K3, TB, dan kepegawaian yang belum '
                . 'digarap; wave ini baru irisan yang bisa dihitung dari data rawat jalan yang sudah ada.',
            'domains'     => ['J', 'O'],
            'publishes'   => [],
        ],

        'hr' => [
            'schema'      => 'hr',
            'module'      => 'Hr',
            'description' => 'Master pegawai, pengajuan cuti, dan presensi harian. Domain C Khanza ("SDM/Kepegawaian") '
                . 'ternyata 34 dari 58 kapabilitasnya adalah audit PPI (audit_bundle_*, audit_cuci_tangan, dst.) dan '
                . 'insiden K3 (bagian_tubuh_k3rs, dampak_cidera_k3rs, dst.) — keduanya konseptual milik konteks '
                . '\'quality\', bukan kepegawaian; menunya kebetulan satu domain huruf. hr di sini hanya mencakup '
                . '24 kapabilitas yang sungguh kepegawaian.',
            'domains'     => ['C'],
            'publishes'   => [],
        ],

    ],

    /*
    | Konteks yang sudah direncanakan tapi belum digarap. Didaftarkan di sini
    | supaya batasnya dipikirkan sejak awal, bukan ditemukan saat kepepet.
    */
    'planned' => [
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
