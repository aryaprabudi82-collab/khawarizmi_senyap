<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Memperlebar consent_type dan certificate_type — bukan tabel baru.
 *
 * Menyisir ~40 kode domain P Khanza yang belum digarap (di luar surat_masuk,
 * pengumuman_epasien, dan 2 dokumen klinis pertama), sebagian besar ternyata
 * bentuknya PERSIS sama dengan dua tabel yang sudah ada — pemeriksaan HIV,
 * penundaan pelayanan, persetujuan rawat inap, dan APS sama-sama "seseorang
 * memutuskan setuju/menolak atas suatu hal, mungkin disaksikan"; bebas
 * narkoba/TBC/buta warna/layak terbang/kewaspadaan kesehatan/COVID/cuti
 * hamil sama-sama "surat berlaku dari-sampai dengan isi keterangan". Jadi
 * bukan tabel baru per jenis — CHECK constraint diperlebar, dan kode
 * Khanza-nya masing-masing tetap digerbangi umbrella yang sama
 * (persetujuan_penolakan_tindakan / surat_keterangan_sehat), sama pola
 * dengan pcra_icra_pengkajian_risiko_prakonstruksi.
 *
 * Sisanya (permintaan privasi, perlindungan dari kekerasan, bimbingan
 * rohani, second opinion, serah terima barang, cuti pasien, skdp_bpjs, dan
 * seluruh metadata filing fisik surat_rak/surat_map/dst.) sengaja belum
 * ikut — bentuknya "permintaan/pernyataan pasien" yang beda dari consent
 * setuju-menolak, atau murni metadata arsip kertas Khanza yang tidak
 * relevan untuk sistem baru. Layak jadi tabel/fitur sendiri kalau memang
 * dibutuhkan, bukan dipaksakan masuk struktur consent/certificate ini.
 */
return new class extends Migration
{
    private const S = 'correspondence';

    public function up(): void
    {
        // certificate_type sebelumnya varchar(20) — 'kewaspadaan_kesehatan' 21 karakter,
        // tidak muat. consent_type sudah varchar(30), aman untuk seluruh jenis baru.
        DB::statement('ALTER TABLE ' . self::S . '.medical_certificates ALTER COLUMN certificate_type TYPE varchar(30)');

        DB::statement('ALTER TABLE ' . self::S . '.patient_consents DROP CONSTRAINT patient_consents_type_check');
        DB::statement("ALTER TABLE " . self::S . ".patient_consents ADD CONSTRAINT patient_consents_type_check
            CHECK (consent_type IN (
                'tindakan','penolakan-anjuran-medis','resusitasi','umum',
                'pemeriksaan-hiv','penundaan-pelayanan','rawat-inap','pulang-permintaan-sendiri'
            ))");

        DB::statement('ALTER TABLE ' . self::S . '.medical_certificates DROP CONSTRAINT medical_certificates_type_check');
        DB::statement("ALTER TABLE " . self::S . ".medical_certificates ADD CONSTRAINT medical_certificates_type_check
            CHECK (certificate_type IN (
                'sehat','sakit','berobat',
                'bebas_narkoba','bebas_tbc','buta_warna','layak_terbang','kewaspadaan_kesehatan','covid','cuti_hamil'
            ))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ' . self::S . '.patient_consents DROP CONSTRAINT patient_consents_type_check');
        DB::statement("ALTER TABLE " . self::S . ".patient_consents ADD CONSTRAINT patient_consents_type_check
            CHECK (consent_type IN ('tindakan','penolakan-anjuran-medis','resusitasi','umum'))");

        DB::statement('ALTER TABLE ' . self::S . '.medical_certificates DROP CONSTRAINT medical_certificates_type_check');
        DB::statement("ALTER TABLE " . self::S . ".medical_certificates ADD CONSTRAINT medical_certificates_type_check
            CHECK (certificate_type IN ('sehat','sakit','berobat'))");
    }
};
