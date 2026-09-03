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
            'publishes'   => [
                'v_employee_summary' => 'Pegawai aktif berikut jabatan dan unit kerjanya. '
                    . 'Dipakai quality untuk memilih pegawai saat mencatat insiden K3.',
            ],
        ],

        'quality' => [
            'schema'      => 'quality',
            'module'      => 'Quality',
            'description' => 'Insiden keselamatan pasien (IKP) dan PCRA/ICRA (kajian risiko pra-konstruksi). '
                . 'insiden_keselamatan(_pasien) tercatat domain M/clinical di Khanza padahal manajemen risiko RS-wide, '
                . 'bukan rekam medis — belum pernah dibangun di modul Clinical, dipindah ke sini. Domain C juga '
                . 'menyimpan 34 kapabilitas audit PPI dan insiden K3 yang konseptual milik konteks ini; belum digarap '
                . 'wave ini.',
            'domains'     => ['R'],
            'publishes'   => [],
        ],

        'inventory' => [
            'schema'      => 'inventory',
            'module'      => 'Inventory',
            'description' => 'Barang non-medis dan penunjang: master barang/suplier, pengajuan dari unit, dan buku '
                . 'besar stok (masuk/keluar/opname). Domain E Khanza 31 kapabilitas, bersih tanpa mis-tagging — pola '
                . 'arsitekturnya diturunkan langsung dari pharmacy.StockLedger (UPDATE bersyarat, ledger append-only), '
                . 'tanpa kerumitan batch/kedaluwarsa yang tidak relevan untuk barang non-medis.',
            'domains'     => ['E'],
            'publishes'   => [],
        ],

        'blood' => [
            'schema'      => 'blood',
            'module'      => 'Blood',
            'description' => 'Unit Transfusi Darah: donor, unit darah dengan siklus status (karantina -> tersedia -> '
                . 'dikeluarkan), dan penyerahan ke pasien. Domain N Khanza 11 kapabilitas, bersih tanpa mis-tagging.',
            'domains'     => ['N'],
            'publishes'   => [],
        ],

        'correspondence' => [
            'schema'      => 'correspondence',
            'module'      => 'Correspondence',
            'description' => 'Surat masuk, surat keluar, pengumuman e-pasien, persetujuan/penolakan tindakan '
                . '(termasuk DNR, HIV, rawat inap, penundaan pelayanan, APS), dan surat keterangan medis '
                . '(sehat/sakit/berobat/bebas narkoba/bebas TBC/buta warna/layak terbang/kewaspadaan kesehatan/'
                . 'covid/cuti hamil) — dicatat terstruktur dengan tampilan cetak, TANPA tanda tangan elektronik '
                . 'sungguhan (butuh signature-pad/canvas, di luar cakupan wave ini). Domain P Khanza 45 kapabilitas; '
                . 'sisanya (permintaan privasi, perlindungan dari kekerasan, bimbingan rohani, second opinion, '
                . 'serah terima barang, cuti pasien, skdp_bpjs, dan metadata filing fisik surat_rak/surat_map/dst.) '
                . 'belum digarap — bentuknya beda dari consent/certificate atau murni arsip kertas Khanza.',
            'domains'     => ['P'],
            'publishes'   => [],
        ],

        'asset' => [
            'schema'      => 'asset',
            'module'      => 'Asset',
            'description' => 'Registri aset/inventaris dan alur permintaan perbaikan. Domain G Khanza 27 kapabilitas '
                . 'menggabungkan 4 area: aset/inventaris umum, CSSD (sirkulasi_cssd, barang_cssd), pemeliharaan, dan '
                . 'kesehatan lingkungan/kesling (limbah B3, mutu air, pest control) — hanya aset/inventaris umum dan '
                . 'pemeliharaan yang digarap wave ini; CSSD dan kesling masing-masing perlu desain tersendiri.',
            'domains'     => ['G'],
            'publishes'   => [],
        ],

    ],

    /*
    | Konteks yang sudah direncanakan tapi belum digarap. Didaftarkan di sini
    | supaya batasnya dipikirkan sejak awal, bukan ditemukan saat kepepet.
    */
    'planned' => [
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
