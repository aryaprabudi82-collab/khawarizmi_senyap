<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Program Rujuk Balik & pelayanan obat apotek BPJS (domain L item G).
 *
 * Menaungi bpjs_program_prb, bpjs_potensi_prb, bpjs_rekap_peserta_prb_apotek,
 * bpjs_daftar_pelayanan_obat_apotek, bpjs_riwayat_pelayanan_obat, dan
 * bpjs_obat_23hari_apotek. Enam kode referensi apotek (DPHO, obat, poli,
 * faskes, spesialistik, setting PPK) sudah tercakup mekanisme referensi
 * penjamin di item E dengan penjamin 'bpjs-apotek' — tidak perlu tabel
 * baru, cukup diisi lewat penyegaran referensi.
 *
 * PRB ADALAH PROGRAM RUJUK BALIK: pasien penyakit kronis yang kondisinya
 * sudah stabil dikembalikan ke faskes tingkat pertama, dan obatnya diambil
 * bulanan di apotek yang ditunjuk. Manfaatnya nyata bagi pasien — tidak
 * perlu antre di rumah sakit tiap bulan hanya untuk menebus obat rutin.
 *
 * "POTENSI PRB" DIHITUNG DARI DATA KITA SENDIRI, bukan ditunggu dari BPJS.
 * Calon peserta adalah pasien dengan diagnosis kronis yang termasuk daftar
 * PRB dan sudah berkunjung berulang kali. Keduanya ada di data kita:
 * diagnosis di clinical, kunjungan di encounter, dan daftar diagnosis PRB
 * di referensi penjamin. Menunggu BPJS memberi tahu siapa calonnya berarti
 * kehilangan kesempatan menawarkan program yang justru meringankan pasien.
 *
 * PENDAFTARAN TIDAK MENGANDAIKAN PERSETUJUAN. Peserta bisa MENOLAK, dan
 * penolakannya dicatat berikut alasannya — bukan dibiarkan kosong seolah
 * belum ditawarkan. Membedakan "belum ditawarkan" dari "sudah ditawarkan
 * dan ditolak" menentukan apakah petugas perlu menghubunginya lagi.
 */
return new class extends Migration
{
    private const S = 'integration';

    private const STATUS = ['calon', 'ditawarkan', 'terdaftar', 'ditolak', 'selesai', 'batal'];

    public function up(): void
    {
        Schema::create(self::S . '.prb_enrollments', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('patient_id');
            $table->string('patient_mrn', 30);
            $table->string('patient_name', 150);
            $table->string('card_number', 20);

            // Diagnosis kronis yang mendasari. Harus termasuk daftar
            // diagnosis PRB BPJS — diperiksa di service, bukan ditebak.
            $table->string('diagnosis_code', 20);
            $table->string('diagnosis_display', 200)->nullable();

            $table->unsignedBigInteger('registration_id')->nullable()->comment('Kunjungan saat program ditawarkan');
            $table->string('sep_number', 30)->nullable();

            // Faskes tingkat pertama tujuan rujuk balik, dan apotek tempat
            // obatnya diambil. Keduanya ditetapkan BPJS, bukan kita.
            $table->string('fktp_code', 40)->nullable();
            $table->string('fktp_name', 150)->nullable();
            $table->string('pharmacy_code', 40)->nullable();
            $table->string('pharmacy_name', 150)->nullable();

            $table->string('status', 20)->default('calon');

            $table->date('offered_on')->nullable();
            $table->date('enrolled_on')->nullable();
            $table->date('valid_until')->nullable()->comment('Masa berlaku surat PRB');

            // Penolakan dicatat berikut alasannya — lihat catatan di atas.
            $table->string('rejection_reason', 200)->nullable();

            $table->string('prb_number', 40)->nullable()->comment('Nomor PRB dari BPJS, bukan dinomori sendiri');
            $table->string('response_code', 10)->nullable();
            $table->string('response_message', 300)->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'enrolled_on']);
            $table->index('card_number');
        });

        DB::statement('ALTER TABLE ' . self::S . ".prb_enrollments
            ADD CONSTRAINT prb_enrollments_status_check
            CHECK (status IN ('" . implode("','", self::STATUS) . "'))");

        // Satu peserta hanya boleh punya satu keikutsertaan PRB aktif per
        // diagnosis: dua pendaftaran untuk penyakit yang sama membuat obat
        // ganda diterbitkan, dan BPJS menolak keduanya saat verifikasi.
        DB::statement('CREATE UNIQUE INDEX prb_enrollment_aktif_unique
            ON ' . self::S . ".prb_enrollments (card_number, diagnosis_code)
            WHERE status IN ('calon','ditawarkan','terdaftar')");

        Schema::create(self::S . '.bpjs_pharmacy_services', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('service_number', 40)->nullable()->comment('Nomor pelayanan dari BPJS');

            $table->string('sep_number', 30);
            $table->string('card_number', 20);
            $table->string('patient_name', 150)->nullable();

            $table->foreignId('prb_enrollment_id')->nullable()->constrained(self::S . '.prb_enrollments');

            // Jenis pelayanan obat: PRB (obat kronis bulanan), kronis
            // (obat kronis di luar PRB), atau obat kemoterapi.
            $table->string('service_type', 20)->default('prb');

            $table->date('served_on');
            $table->unsignedSmallInteger('day_supply')->nullable()
                ->comment('Jumlah hari obat; BPJS membatasi 30 hari untuk PRB dan 23 hari untuk obat kronis');

            $table->decimal('total_amount', 15, 2)->default(0);

            $table->string('status', 20)->default('terkirim');
            $table->string('response_code', 10)->nullable();
            $table->string('response_message', 300)->nullable();
            $table->json('items')->nullable()->comment('Rincian obat yang dilaporkan, dibekukan saat dikirim');

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestampsTz();

            $table->index(['served_on', 'service_type']);
            $table->index('sep_number');
        });

        DB::statement('ALTER TABLE ' . self::S . ".bpjs_pharmacy_services
            ADD CONSTRAINT bpjs_pharmacy_services_type_check
            CHECK (service_type IN ('prb','kronis','kemoterapi'))");

        DB::statement('ALTER TABLE ' . self::S . ".bpjs_pharmacy_services
            ADD CONSTRAINT bpjs_pharmacy_services_status_check
            CHECK (status IN ('terkirim','gagal','batal'))");
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.bpjs_pharmacy_services');
        Schema::dropIfExists(self::S . '.prb_enrollments');
    }
};
