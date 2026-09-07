<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Berkas digital & retensi rekam medis (domain M item L).
 *
 * Menaungi berkas_digital_perawatan dan retensi_rm.
 *
 * TIGA KODE DINYATAKAN TIDAK BERLAKU, mengikuti preseden
 * lama_penyiapan_rm yang sudah disepakati pada domain J item D:
 * mutasi_berkas dan peminjaman_berkas mengurus PERGERAKAN BERKAS
 * KERTAS antar unit dan peminjamannya. Rekam medis di sini elektronik
 * sejak awal mengikuti Permenkes 24/2022, jadi tidak ada map yang
 * dikirim, diterima, atau dipinjam. Membangunnya berarti membuat layar
 * yang tidak akan pernah ada isinya. riwayat_kamar_pasien sudah
 * tertutup inpatient.bed_assignments sejak domain I, satu baris per
 * periode penempatan.
 *
 * BERKAS DIGITAL KHANZA HANYA PUNYA TIGA KOLOM: no_rawat, kode, dan
 * lokasi_file varchar(600). Tidak ada siapa yang mengunggah, kapan,
 * berkas aslinya bernama apa, tipenya apa, besarnya berapa, maupun
 * sidiknya. Akibatnya:
 *
 * - Tidak ada jejak audit. Permenkes 24/2022 menuntut rekam medis
 *   elektronik punya jejak siapa mengakses dan mengubah apa; berkas
 *   yang muncul tanpa pengunggah tidak bisa dipertanggungjawabkan.
 * - Berkasnya bisa DIGANTI DIAM-DIAM. Yang disimpan cuma jalur berkas,
 *   jadi menimpa berkas di jalur itu mengubah isi rekam medis tanpa
 *   meninggalkan bekas apa pun. Karena itu di sini disimpan
 *   checksum SHA-256: bukan untuk mengunci berkasnya, tapi supaya
 *   penggantian bisa KETAHUAN.
 *
 * RALAT BERKAS TIDAK MENIMPA. Berkas yang salah unggah ditandai
 * diganti dan menunjuk penggantinya; yang lama tetap ada. Rekam medis
 * yang bisa dihapus tanpa jejak bukan rekam medis.
 *
 * TANGGAL RETENSI DIHITUNG, TIDAK DISIMPAN. retensi_pasien Khanza
 * menyimpan tgl_retensi di samping terakhir_daftar, padahal yang
 * pertama adalah yang kedua ditambah masa simpan. Dua akibatnya: bisa
 * berbeda dari kunjungan terakhir yang sebenarnya, dan begitu masa
 * simpannya diubah peraturan, SELURUH barisnya basi tanpa ada yang
 * tahu. Di sini masa simpannya satu konstanta dan tanggalnya dihitung.
 *
 * MASA SIMPAN 25 TAHUN mengikuti Permenkes 24/2022 Pasal 39: rekam
 * medis elektronik disimpan sekurang-kurangnya 25 tahun sejak tanggal
 * terakhir pasien berobat.
 */
return new class extends Migration
{
    private const S = 'clinical';

    public function up(): void
    {
        $this->createDocuments();
        $this->createRetentions();
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S.'.record_retentions');
        Schema::dropIfExists(self::S.'.medical_record_documents');
    }

    private function createDocuments(): void
    {
        Schema::create(self::S.'.medical_record_documents', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('patient_id');
            $table->string('registration_number', 30);
            $table->string('patient_mrn', 30);
            $table->string('patient_name', 150);

            // Jenis dibekukan: master boleh berubah nama, berkas yang sudah
            // diunggah tetap tercatat sebagai jenis yang dipilih waktu itu.
            $table->string('document_type_code', 30);
            $table->string('document_type_name', 150);

            $table->string('original_filename', 255)
                ->comment('Nama berkas dari pengunggah — dipakai saat diunduh kembali');
            $table->string('stored_path', 600);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->string('checksum_sha256', 64)
                ->comment('Bukan pengunci, tapi supaya penggantian berkas bisa ketahuan');

            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->string('uploaded_by_name', 150);
            $table->timestampTz('uploaded_at');

            $table->text('note')->nullable();

            $table->string('status', 20)->default('aktif')->comment('aktif, diganti, dibatalkan');
            $table->unsignedBigInteger('superseded_by_id')->nullable()
                ->comment('Berkas pengganti; yang lama tetap ada');
            $table->string('cancellation_reason', 255)->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['registration_id', 'document_type_code']);
            $table->index(['patient_id', 'uploaded_at']);
            $table->index(['status', 'uploaded_at']);
        });

        DB::statement('ALTER TABLE '.self::S.".medical_record_documents
            ADD CONSTRAINT medical_record_documents_status_check
            CHECK (status IN ('aktif','diganti','dibatalkan'))");

        // Sidik yang bukan SHA-256 menandakan yang disimpan bukan sidik.
        DB::statement('ALTER TABLE '.self::S.".medical_record_documents
            ADD CONSTRAINT medical_record_documents_checksum_check
            CHECK (checksum_sha256 ~ '^[0-9a-f]{64}$')");

        DB::statement('ALTER TABLE '.self::S.'.medical_record_documents
            ADD CONSTRAINT medical_record_documents_size_check
            CHECK (size_bytes > 0)');

        // Berkas yang ditandai diganti harus menyebut penggantinya, dan yang
        // dibatalkan harus menyebut alasannya. Tanpa keduanya, status hanya
        // penanda yang bisa dipasang tanpa menjelaskan apa pun.
        DB::statement('ALTER TABLE '.self::S.".medical_record_documents
            ADD CONSTRAINT medical_record_documents_supersede_check
            CHECK ((status <> 'diganti' OR superseded_by_id IS NOT NULL)
                   AND (status <> 'dibatalkan'
                        OR (cancellation_reason IS NOT NULL AND btrim(cancellation_reason) <> '')))");

        // Berkas yang sama, jenis yang sama, kunjungan yang sama: unggahan
        // ganda karena tombolnya ditekan dua kali. Yang dibatalkan dan yang
        // sudah diganti tidak ikut dihitung.
        DB::statement('CREATE UNIQUE INDEX medical_record_documents_no_duplicate
            ON '.self::S.".medical_record_documents (registration_id, document_type_code, checksum_sha256)
            WHERE status = 'aktif' AND deleted_at IS NULL");
    }

    private function createRetentions(): void
    {
        Schema::create(self::S.'.record_retentions', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('patient_id')->unique();
            $table->string('patient_mrn', 30);
            $table->string('patient_name', 150);

            $table->date('last_visit_on')
                ->comment('Dasar hitungan masa simpan; TIDAK ada kolom tanggal retensi — dihitung');

            $table->string('status', 30)->default('aktif')
                ->comment('aktif, diusulkan-musnah, dimusnahkan, diabadikan');

            // Ringkasan yang dipindai sebelum berkas aslinya dimusnahkan
            // (padanan lokasi_pdf Khanza).
            $table->string('summary_path', 600)->nullable();

            $table->date('proposed_on')->nullable();
            $table->string('proposal_number', 60)->nullable();
            $table->timestampTz('destroyed_at')->nullable();
            $table->string('destruction_decision_number', 60)->nullable();
            $table->unsignedBigInteger('destroyed_by')->nullable();
            $table->string('destroyed_by_name', 150)->nullable();

            $table->text('note')->nullable();

            $table->timestampsTz();

            $table->index(['status', 'last_visit_on']);
        });

        DB::statement('ALTER TABLE '.self::S.".record_retentions
            ADD CONSTRAINT record_retentions_status_check
            CHECK (status IN ('aktif','diusulkan-musnah','dimusnahkan','diabadikan'))");

        // Pemusnahan rekam medis adalah tindakan yang tidak bisa dibatalkan,
        // dan syaratnya berita acara — bukan kehendak seorang petugas.
        DB::statement('ALTER TABLE '.self::S.".record_retentions
            ADD CONSTRAINT record_retentions_destroyed_check
            CHECK (status <> 'dimusnahkan'
                   OR (destroyed_at IS NOT NULL
                       AND destruction_decision_number IS NOT NULL
                       AND btrim(destruction_decision_number) <> ''
                       AND destroyed_by_name IS NOT NULL))");
    }
};
