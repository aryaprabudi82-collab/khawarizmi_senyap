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
                    . 'Dipakai billing untuk menentukan siapa yang menanggung tagihan, dan encounter untuk '
                    . 'daftar pilihan penjamin saat mendaftarkan pasien.',
                'v_form_template' => 'Template formulir asesmen & skrining berikut SELURUH versinya, '
                    . 'pertanyaannya, dan aturan skornya (domain M item A). Dipakai clinical menyusun dan '
                    . 'menilai formulir. Versi LAMA ikut diterbitkan dan itu disengaja: formulir yang diisi '
                    . 'tahun lalu harus dibaca kembali dengan pertanyaan yang berlaku waktu itu, dan kontrak '
                    . 'yang hanya memuat versi aktif akan membuat rekam medis lama kehilangan pertanyaannya.',
                'v_fluid_item' => 'Master jenis cairan masuk & keluar berikut ARAHNYA (domain M item E). '
                    . 'Arah melekat pada jenisnya supaya tidak bisa dikirim terpisah: urine selalu keluar, '
                    . 'dan arah yang bisa dibalik membuka celah urine tercatat sebagai asupan.',
                'v_observation_code' => 'Katalog jenis pengukuran yang bisa dicatat pada pasien berikut '
                    . 'satuan, tipe nilai, dan rentang BAWAANNYA (domain M item D).',
                'v_observation_panel_item' => 'Butir panel observasi berikut rentang rujukan yang BERLAKU — '
                    . 'rentang panel bila diisi, kalau tidak rentang bawaan kodenya (domain M item D). '
                    . 'Penggabungan itu dilakukan di kontrak, bukan diserahkan ke tiap konsumen: aturan '
                    . '"rentang panel mengalahkan bawaan" yang ditemukan ulang di banyak tempat akan benar '
                    . 'di sebagian tempat saja, dan yang salah menandai seluruh bayi abnormal sepanjang hari.',
                'v_nursing_problem' => 'Master masalah keperawatan (diagnosis keperawatan) berikut '
                    . 'spesialisasi dan kode SDKI-nya bila ada — domain M item B. Menaungi 8 tabel master '
                    . 'Khanza yang berbentuk identik dan cuma berbeda spesialisasinya.',
                'v_nursing_care_plan' => 'Master rencana keperawatan BERIKUT kode masalah induknya — '
                    . 'domain M item B. Hierarkinya ikut diterbitkan, bukan disembunyikan: Khanza sendiri '
                    . 'memasang foreign key dari rencana ke masalah, dan konsumen harus bisa menegakkan '
                    . 'aturan "rencana melekat pada masalahnya" tanpa menemukan ulang aturannya sendiri.',
            ],
        ],

        'organization' => [
            'schema'      => 'organization',
            'module'      => 'Organization',
            'description' => 'Unit layanan, poliklinik, ruang, bangsal, praktisi (SIP, spesialisasi, periode aktif), '
                . 'dan jadwal praktik mingguan. jadwal_praktek tercatat domain A/context=encounter di katalog, '
                . 'paket Java-nya "kepegawaian" (lihat Khanza_Functional_Dependency_Map.xlsx) — sengaja TETAP '
                . 'dibangun di sini (bukan hr) karena practitioners sudah dipisah dari hr.employees justru supaya '
                . 'ketersediaan klinis tidak bergantung modul kepegawaian; lihat catatan migrasi '
                . 'practice_schedules. Wave 1 murni data jadwal (CRUD admin), belum dipakai memvalidasi '
                . 'registrasi — akan dikonsumsi saat booking_registrasi/booking_periksa dibangun.',
            'domains'     => ['U', 'C', 'A'],
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
            'description' => 'Registrasi, booking (booking_registrasi/booking_periksa — mendaftar untuk '
                . 'tanggal mendatang, tervalidasi terhadap jadwal praktik mingguan organization.'
                . 'practice_schedules, bukan cuma masa aktif SIP), antrean, penetapan DPJP, rujukan_masuk/'
                . 'rujukan_keluar, dan igd (registrasi ke unit IGD tanpa kuota + triase warna standar '
                . 'merah/kuning/hijau/hitam, memakai jalur registrasi biasa dengan care_type=igd baru, bukan '
                . 'alur terpisah). Registrasi hari ini (walk-in) tidak diperiksa terhadap jadwal — validasi '
                . 'jadwal cuma berlaku untuk tanggal setelah hari ini.',
            'domains'     => ['A'],
            'publishes'   => [
                'v_registration_summary' => 'Kunjungan aktif berikut pasien, unit, dokter, dan penjaminnya. '
                    . 'Dipakai clinical, order, pharmacy, billing, dan inpatient (mencari registrasi ranap '
                    . 'yang belum dapat kamar) sebagai konteks kunjungan.',
                'v_triage_summary' => 'Triase IGD berikut kunjungan sahnya (domain J item C, untuk RL 3.2 Rawat Darurat). '
                    . 'Menyaring lewat kontrak kunjungan yang sah, jadi kunjungan batal tidak ikut.',
                'v_registration_cancellation' => 'Kunjungan yang DIBATALKAN, kontrak terpisah khusus laporan '
                    . '(domain J item A, kode pembatalan_periksa_dokter). Sengaja tidak digabung ke '
                    . 'v_registration_summary: kontrak itu membuang kunjungan batal supaya billing dan klinis '
                    . 'tidak pernah menindaklanjutinya, dan invarian itu tidak boleh dilonggarkan demi laporan.',
                'v_outgoing_referral' => 'Rujukan keluar berikut faskes tujuan, alasan, dan diagnosisnya '
                    . '(domain L item P). Dipakai integration untuk MENGIRIM rujukan ke Sisrute tanpa '
                    . 'menyalin isinya: rujukannya tetap milik encounter, dan yang dicatat integration cuma '
                    . 'pengiriman berikut jawaban rumah sakit tujuan. Tanpa kontrak ini satu-satunya jalan '
                    . 'adalah menyalin isi rujukan ke integration — dan salinan berarti dua sumber kebenaran, '
                    . 'dengan yang dikirim ke Sisrute justru bisa jadi yang sudah basi.',
            ],
        ],

        'clinical' => [
            'schema'      => 'clinical',
            'module'      => 'Clinical',
            'description' => 'Rekam medis elektronik: asesmen, SOAP, tanda vital, diagnosis, alergi, skrining '
                . 'awal rawat jalan (risiko jatuh/nyeri/gizi/gejala menular), dan tindakan rawat jalan '
                . '(tindakan_ralan) berikut tarifnya. Mengacu Permenkes 24/2022. sekrining_rawat_jalan dan '
                . 'tindakan_ralan tercatat domain A/context=encounter di katalog — sekrining karena kelas '
                . 'Java-nya (RMSKriningRawatJalan) berpaket "rekammedis" (lihat '
                . 'Khanza_Functional_Dependency_Map.xlsx), tindakan_ralan karena "apa yang terjadi ke pasien" '
                . 'adalah rekam medis meski Khanza sendiri tidak memberi penanda paket lain untuknya. Keduanya '
                . 'digerbangi umbrella penilaian_awal_medis_ralan yang sudah ada, bukan kode terpisah. '
                . 'deteksi_corona (domain A, kelas RMDeteksiDiniCorona, paket "rekammedis" — juga clinical, '
                . 'tanpa relokasi) SENGAJA tidak dibangun sebagai layar tersendiri, disetujui pengguna '
                . '2026-09-03: skrining COVID-19 khusus adalah fitur era kedaruratan pandemi (status PHEIC '
                . 'dicabut 2023), bukan kewajiban akreditasi Kemenkes/KARS yang terus berlaku seperti skrining '
                . 'itu sendiri — flag infectious_symptom umum di clinical.screenings sudah menutup kebutuhan '
                . '"ada gejala menular yang perlu diwaspadai", sama seperti billing_ralan yang dianggap '
                . 'terpenuhi oleh pembayaran_ralan. operasi (domain A, "Operasi/VK", kelas '
                . 'DlgCariTagihanOperasi, tanpa penanda paket lain) juga direlokasi ke sini — sama persis '
                . 'alasannya dengan tindakan_ralan — tapi gerbangnya SENDIRI (bukan umbrella), karena tarifnya '
                . 'jauh lebih besar dan tim operator berbeda dari tindakan ralan biasa. Tarif operasi masih '
                . 'lump-sum, belum dipecah per peran tim bedah seperti Khanza asli — lihat catatan migrasi '
                . 'clinical.operations.',
            'domains'     => ['M', 'A'],
            'publishes'   => [
                'v_patient_allergy' => 'Alergi aktif per pasien. Dipakai pharmacy untuk telaah resep.',
                'v_encounter_diagnosis' => 'Diagnosis per kunjungan berikut kode ICD-10. '
                    . 'Dipakai billing untuk pengajuan klaim.',
                'v_procedure_charge' => 'Tindakan rawat jalan yang sudah dicatat berikut nilainya. '
                    . 'Dipakai billing untuk menyusun baris tagihan, pola sama dengan '
                    . 'pharmacy.v_prescription_charge dan orders.v_order_charge.',
                'v_operation_charge' => 'Operasi yang sudah dicatat berikut nilainya. '
                    . 'Dipakai billing untuk menyusun baris tagihan, pola sama dengan v_procedure_charge.',
                'v_screening_summary' => 'Skrining awal rawat jalan, hanya kolom yang dibutuhkan laporan '
                    . '(gejala infeksius & risiko gizi) — domain J item E. Hasil skrining nyeri dan risiko jatuh sengaja '
                    . 'tidak dipaparkan: itu data klinis, bukan bahan laporan tahunan.',
                'v_operation_summary' => 'Kegiatan pembedahan berikut jenis anestesi, kamar operasi, dan operatornya '
                    . '(domain J item C, untuk RL 3.6). Terpisah dari v_operation_charge yang berbentuk penagihan '
                    . 'dan tidak membawa rincian kegiatan ini.',
                'v_diagnosis_code' => 'Kamus ICD-10 berikut bab, sifat penularan, dan kelompok DTD-nya '
                    . '— daftar kodenya, bukan diagnosis pasien. Dipakai reporting untuk menyatakan apakah DTD '
                    . 'sudah diimpor sebelum RL 4A/4B dikirim.',
                'v_diagnosis_surveillance_group' => 'Keanggotaan kode diagnosis pada program surveilans '
                    . '(pd3i, afp, tb-sitt, dan program berikutnya) — domain J item B. Diterbitkan TERPISAH dari '
                    . 'v_encounter_diagnosis karena satu penyakit bisa masuk beberapa kelompok; menggabungkannya '
                    . 'akan menggandakan baris diagnosis dan membuat hitungan morbiditas terlalu besar. Dipakai '
                    . 'reporting sebagai penyaring keanggotaan, bukan tabel yang ikut dijumlahkan.',
                'v_assessment_summary' => 'Asesmen berikut empat bagian SOAP-nya, status, versi, dan waktu '
                    . 'finalisasinya (domain L item O). Dipakai integration menyusun ClinicalImpression '
                    . '(bagian "A") dan CarePlan (bagian "P") SATUSEHAT — keduanya berasal dari catatan yang '
                    . 'sama, jadi satu kontrak bukan dua. Status dan finalized_at ikut diterbitkan supaya '
                    . 'konsumen bisa menolak mengirim asesmen yang masih draf: penilaian klinis yang belum '
                    . 'dinyatakan selesai tidak boleh tersebar ke fasilitas lain.',
            ],
        ],

        'pharmacy' => [
            'schema'      => 'pharmacy',
            'module'      => 'Pharmacy',
            'description' => 'Resep (rawat jalan & resep_pulang lewat kolom kind, domain A Khanza tapi paket '
                . 'Java-nya "inventory" — dibangun di sini karena sama-sama memicu potong stok), telaah '
                . 'apoteker, penyerahan, dan stok dengan batch serta kedaluwarsa.',
            'domains'     => ['D', 'A'],
            'publishes'   => [
'v_goods_receipt' => 'Penerimaan barang berikut nilai terimanya (quantity diterima x harga baris PO) '
                    . '— domain K item B, dipakai finance menyusun hutang vendor lintas empat rantai pengadaan. '
                    . 'Nomor faktur dan status bayar sengaja TIDAK ikut: hutang dan pelunasannya milik finance, '
                    . 'dan memaparkannya dari sini melahirkan dua sumber kebenaran yang bisa berbeda.',
                'v_prescription_duration' => 'Rantai waktu resep (diresepkan/diserahkan/ditelaah/diserahkan-ke-pasien), SATU BARIS PER RESEP '
                    . '— domain J item D, untuk lama_pelayanan_apotek. Sengaja terpisah dari v_prescription_charge yang '
                    . 'berbentuk penagihan per baris obat dan akan mencondongkan rata-rata ke resep yang isinya paling banyak.',
                'v_prescription_charge' => 'Obat yang sudah diserahkan berikut nilainya. Dipakai billing untuk menarik biaya obat ke tagihan kunjungan.',
                'v_prescription_detail' => 'Rincian resep per BARIS OBAT berikut aturan pakainya, kode KFA, '
                    . 'penanda narkotika/psikotropika/high-alert, dan substitusi apoteker (domain L item I). '
                    . 'Dipakai integration menyusun Medication, MedicationRequest, dan MedicationDispense '
                    . 'SATUSEHAT. Jumlah DIRESEPKAN dan jumlah DISERAHKAN sengaja terbit sebagai dua kolom: '
                    . 'saat keduanya berbeda (stok kurang), MedicationRequest memakai yang pertama dan '
                    . 'MedicationDispense yang kedua — menggabungkannya membuat salah satunya berbohong.',
                'v_prescription_review' => 'Hasil telaah apoteker berikut temuannya (domain L item I). Dipakai '
                    . 'integration menyusun QuestionnaireResponse telaah farmasi. Temuan disimpan apa adanya '
                    . 'karena telaah yang tidak menemukan apa-apa dan telaah yang belum dikerjakan adalah dua '
                    . 'pernyataan berbeda.',
                'v_drug_catalog' => 'Master obat TERBATAS pada identitasnya — kode, nama, generik, KFA, bentuk, '
                    . 'kekuatan, dan penanda narkotika/psikotropika/high-alert (domain L item I). Harga, stok, '
                    . 'dan margin sengaja TIDAK ikut: konsumen hanya perlu tahu obat apa saja yang ada dan '
                    . 'bagaimana menyebutnya, sedangkan memaparkan harga dari sini melahirkan sumber kedua bagi '
                    . 'angka yang sudah dimiliki billing.',
            ],
        ],

        'billing' => [
            'schema'      => 'billing',
            'module'      => 'Billing',
            'description' => 'Tagihan dan pembayaran rawat jalan. Charge line ditarik dari encounter, pharmacy, order, dan clinical (tindakan_ralan, operasi).',
            'domains'     => ['I'],
            'publishes'   => [
                'v_settled_invoice' => 'Tagihan yang sudah lunas atau ditanggung penjamin. '
                    . 'Dipakai finance untuk memposting jurnal pendapatan dan membuka piutang.',
                'v_payment_detail' => 'Pembayaran yang sah (yang dibatalkan dan tagihan void tidak muncul) '
                    . 'berikut cara bayarnya. Dipakai finance untuk memetakan uang masuk ke akun (domain I item E, '
                    . 'padanan tabel akun_bayar Khanza).',
                'v_charge_detail' => 'Baris biaya berikut jenis sumbernya (registrasi/kamar/tindakan/obat/...). '
                    . 'Dipakai finance untuk memetakan pendapatan ke akun dan menutup periode.',
            ],
        ],

        'order' => [
            'schema'      => 'orders',
            'module'      => 'Order',
            'description' => 'Siklus permintaan penunjang lab, radiologi, dan patologi anatomi pasien: order, proses, hasil, verifikasi.',
            // Domain B Khanza ("Lab Kesehatan Lingkungan") BUKAN ini - itu uji
            // air/makanan, sudah jadi konteks 'envlab' terpisah. Permission
            // tulis untuk periksa_lab/periksa_radiologi/pemeriksaan_lab_pa
            // justru nyasar ke domain A karena menu Khanza mencampurnya
            // dengan registrasi, sama seperti kasus resep_obat - diberikan
            // eksplisit lewat extra_permissions peran, bukan lewat
            // context-grant. pemeriksaan_lab_pa (Periksa Lab PA) memakai
            // kategori 'pa' di siklus order yang sama - hasilnya selalu
            // naratif, tidak ada rentang rujukan numerik.
            'domains'     => ['A'],
            'publishes'   => [
                'v_order_charge' => 'Pemeriksaan lab/radiologi/PA yang sudah selesai berikut nilainya. '
                    . 'Dipakai billing untuk menarik biaya penunjang ke tagihan kunjungan.',
                'v_order_summary' => 'Satu baris per PERMINTAAN penunjang berikut kategori dan statusnya '
                    . '(domain J item A). Dipakai reporting untuk menghitung kunjungan permintaan lab/radiologi '
                    . '— termasuk yang belum selesai maupun dibatalkan, yang justru tidak muncul di '
                    . 'v_order_charge dan akan membuat angkanya terlalu kecil tanpa terlihat salah.',
                'v_order_result' => 'Satu baris per BUTIR pemeriksaan berikut hasilnya, rentang rujukannya, '
                    . 'jenis spesimen, dan modalitasnya (domain L item H). Dipakai integration menyusun '
                    . 'ServiceRequest, Specimen, Observation, dan DiagnosticReport SATUSEHAT — keempatnya '
                    . 'berbicara tentang satu butir pemeriksaan, bukan tentang lembar permintaannya. '
                    . 'specimen_type kosong berarti pemeriksaan itu memang tidak mengambil bahan dari '
                    . 'pasien (radiologi), bukan berarti datanya belum diisi.',
            ],
        ],

        'finance' => [
            'schema'      => 'finance',
            'module'      => 'Finance',
            'description' => 'Jurnal berpasangan dan piutang penjamin. Menutup label "ditanggung-penjamin" di '
                . 'billing menjadi kewajiban yang bisa diaudit. deposit_pasien dan perkiraan_biaya_ranap '
                . 'tercatat domain A/context=encounter di katalog (menu Khanza mencampur registrasi dengan '
                . 'menu keuangan), tapi kelasnya (DlgDeposit, DlgPerkiraanBiayaRanap) berpaket Java '
                . '"keuangan" — dibangun di sini. deposit_pasien dijurnal (Kas/Titipan Deposit Pasien); '
                . 'perkiraan_biaya_ranap murni kutipan, tidak dijurnal, tarif kamarnya dibaca dari '
                . 'inpatient.v_room_class_rate. "Memakai" deposit (status terpakai) SENGAJA belum ada — itu '
                . 'kode Khanza terpisah, pengembalian_deposit_pasien, domain K, menyusul saat domain itu digarap.',
            'domains'     => ['K', 'A'],
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
            'publishes'   => [
'v_goods_receipt' => 'Penerimaan barang berikut nilai terimanya (quantity diterima x harga baris PO) '
                    . '— domain K item B, dipakai finance menyusun hutang vendor lintas empat rantai pengadaan. '
                    . 'Nomor faktur dan status bayar sengaja TIDAK ikut: hutang dan pelunasannya milik finance, '
                    . 'dan memaparkannya dari sini melahirkan dua sumber kebenaran yang bisa berbeda.',
            ],
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
            'publishes'   => [
'v_goods_receipt' => 'Penerimaan barang berikut nilai terimanya (quantity diterima x harga baris PO) '
                    . '— domain K item B, dipakai finance menyusun hutang vendor lintas empat rantai pengadaan. '
                    . 'Nomor faktur dan status bayar sengaja TIDAK ikut: hutang dan pelunasannya milik finance, '
                    . 'dan memaparkannya dari sini melahirkan dua sumber kebenaran yang bisa berbeda.',
                'v_cssd_circulation' => 'Sirkulasi set CSSD berikut keempat stempel waktunya (diterima/diproses/steril/'
                    . 'didistribusikan) — domain J item D, untuk lama_pelayanan_cssd. Rantainya sudah lengkap sejak '
                    . 'domain G, jadi laporan ini tidak butuh pencatatan baru, cuma kontraknya.',
            ],
        ],

        'inpatient' => [
            'schema'      => 'inpatient',
            'module'      => 'Inpatient',
            'description' => 'Kamar/bed dan admisi rawat inap (masuk, siklus bed tersedia-terisi-dibersihkan, '
                . 'keluar), order diet (diet_pasien), dan riwayat DPJP dengan aksi ganti DPJP (dpjp_ranap) untuk '
                . 'alih rawat/konsul di tengah rawatan. Ranap Khanza sebenarnya ~70 kapabilitas tersebar di domain '
                . 'A/B/D/I/J/K/L/M/O/P — nursing notes, billing per-hari, SIRANAP, RL4A, dst. Wave 1 ini '
                . 'fondasinya saja. Registrasi ranap TETAP di encounter (care_type=ranap pada registrasi biasa) — '
                . 'modul ini membaca registrasi yang belum dapat kamar, tidak mendaftarkan pasien sendiri. '
                . 'Nursing/medical assessment ranap (domain M), billing akumulasi harian (domain I), dan '
                . 'integrasi SIRANAP (domain L) belum digarap.',
            'domains'     => ['A', 'K'],
            'publishes'   => [
                'v_room_class_rate' => 'Tarif kamar rata-rata per kelas (kamar nonaktif tidak dihitung). '
                    . 'Dipakai finance untuk perkiraan_biaya_ranap tanpa menyentuh inpatient.rooms langsung.',
                'v_room_charge' => 'Biaya kamar satu baris per hari menginap (domain I item A). Dipakai billing '
                    . 'untuk menagihkan kamar rawat inap — sebelumnya biaya kamar tidak pernah sampai ke tagihan. '
                    . 'Hari yang ditagih: tanggal masuk s.d. sehari sebelum pulang (minimal 1 hari), atau s.d. hari '
                    . 'ini kalau masih dirawat. Tahan terhadap pindah kamar di tengah rawat: tiap hari memakai '
                    . 'bed yang sungguh ditempati hari itu, diambil dari bed_assignments — hari sebelum pindah '
                    . 'tetap memakai tarif kamar lama. Kalau pindahnya di tengah hari, hari itu ditagihkan ke '
                    . 'kamar yang ditempati sampai malam, karena tarif kamar adalah tarif per malam.',
                'v_diet_order' => 'Permintaan diet berikut HARI-DIET-nya (selisih mulai-selesai) — domain J item E. '
                    . 'Hari-diet, bukan jumlah permintaan: satu permintaan lima hari adalah lima hari pemberian, dan '
                    . 'menghitungnya sebagai satu membuat angka gizi jauh lebih kecil daripada kenyataannya.',
                'v_bed_assignment' => 'Rentang penempatan bed (assigned_at/released_at), tanpa identitas pasien '
                    . '— domain J item D, untuk hari-rawat pada hitungan BOR. Dihitung dari penempatan yang sungguh '
                    . 'terjadi supaya pasien yang pindah kamar tidak terlewat maupun tergandakan.',
                'v_bed_availability' => 'Jumlah tempat tidur per kelas dan statusnya, kamar nonaktif tidak dihitung '
                    . '(domain J item C, untuk RL 1.3). Yang dilaporkan kapasitas terpasang, dan kamar yang ditutup '
                    . 'bukan kapasitas yang tersedia.',
                'v_admission_summary' => 'Daftar admisi berikut ruang, kelas, DPJP, dan status pulangnya '
                    . '(domain J item A). Dipakai reporting untuk sensus ranap, daftar pasien dirawat, dan '
                    . 'asal poli/dokter — pertanyaan yang tidak terjawab oleh dua kontrak biaya di atas.',
            ],
        ],

        'kitchen' => [
            'schema'      => 'kitchen',
            'module'      => 'Kitchen',
            'description' => 'Bahan pangan & penunjang dapur/gizi: master barang/suplier, pengajuan dari unit, dan '
                . 'buku besar stok (masuk/keluar/opname). Domain F Khanza ("Dapur & Gizi") 25 kapabilitas genuine '
                . '(asal_hibah dan satuan_barang yang ikut nongol di menunya adalah kode reused lintas-domain, '
                . 'sudah diselesaikan ke context=pharmacy saat domain D dibangun), bersih tanpa mis-tagging. '
                . 'Paket Java "dapur", context=kitchen sendiri di katalog — genuinely bounded context terpisah '
                . 'dari inventory (paket "ipsrs"/"inventory"), meski arsitekturnya sengaja meniru persis (item '
                . 'non-batch, StockLedger UPDATE bersyarat) karena Khanza memberi domain F struktur menu yang '
                . 'nyaris identik dengan domain E. Wave 1 non-perishable-aware — bahan basah dengan kedaluwarsa '
                . 'harian belum digarap.',
            'domains'     => ['F'],
            'publishes'   => [
'v_goods_receipt' => 'Penerimaan barang berikut nilai terimanya (quantity diterima x harga baris PO) '
                    . '— domain K item B, dipakai finance menyusun hutang vendor lintas empat rantai pengadaan. '
                    . 'Nomor faktur dan status bayar sengaja TIDAK ikut: hutang dan pelunasannya milik finance, '
                    . 'dan memaparkannya dari sini melahirkan dua sumber kebenaran yang bisa berbeda.',
            ],
        ],

        'parking' => [
            'schema'      => 'parking',
            'module'      => 'Parking',
            'description' => 'Parkir kendaraan pengunjung/pegawai: jenis & tarif parkir, stok kartu barcode, dan '
                . 'sesi parkir masuk-keluar dengan perhitungan durasi & biaya. Domain H Khanza ("Parkir") hanya 3 '
                . 'kode dan tidak punya menu untuk sisi keluar — tapi tabel `parkir` Khanza sendiri sudah punya '
                . 'tgl_keluar/jam_keluar/lama_parkir/ttl_biaya, jadi mencatat keluar & menghitung biaya memang '
                . 'selalu dimaksudkan, bukan tambahan di luar Khanza. Yang didelegasikan Khanza ke vendor luar '
                . 'adalah rekap keluarnya (duta_parkir_rekap_keluar, domain L context=integration) — SIMRS Mandiri '
                . 'tidak terikat vendor itu, jadi sisi keluar dilayani sendiri di sini. parkir_barcode dilebur ke '
                . 'layar master (gerbang parkir_jenis), bukan ke layar transaksi: di Khanza tabelnya cuma pemetaan '
                . 'kode_barcode->nomer_kartu tanpa timestamp, jadi itu stok kartu fisik yang didaftarkan admin, '
                . 'bukan pekerjaan petugas gerbang.',
            'domains'     => ['H'],
            'publishes'   => [],
        ],
        'envlab' => [
            'schema'      => 'envlab',
            'module'      => 'Envlab',
            'description' => 'Laboratorium kesehatan lingkungan & K3 (Khanza domain B "Barcode & Lab Kesling") — '
                . 'pengujian sampel air/udara/makanan/usap alat, BUKAN tentang pasien. Pelanggan bisa internal '
                . '(K3RS/kesling RS sendiri) atau eksternal. Seluruh 16 kode domain B berpaket Java "viabarcode" '
                . '(satu paket untuk seluruh grup menu, bukan penanda relokasi per kode seperti domain A) — '
                . 'barcoderalan/barcoderanap (cetak label kunjungan pasien) tetap direlokasi ke encounter meski '
                . 'tercatat context=envlab di katalog, karena fungsinya genuinely soal kunjungan, bukan lab '
                . 'lingkungan. Data master (pelanggan, jenis sampel/master_sampel_bakumutu, parameter pengujian, '
                . 'nilai baku mutu) Wave 1 ini; alur transaksi (permintaan->triase->penugasan->hasil->verifikasi/'
                . 'validasi) dan rekap/pembayaran menyusul.',
            'domains'     => ['B'],
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
