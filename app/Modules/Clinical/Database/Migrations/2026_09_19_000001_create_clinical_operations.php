<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * operasi (Khanza domain A, "Operasi/VK", kelas DlgCariTagihanOperasi) —
 * tercatat context=encounter di katalog, TANPA penanda paket Java lain
 * (kolom paketnya kosong di Khanza_Functional_Dependency_Map.xlsx sheet2,
 * beda dari booking_operasi yang jelas "permintaan"). Tetap dibangun di
 * clinical, bukan encounter — alasan sama persis dengan tindakan_ralan:
 * prosedur yang dilakukan ke pasien (di sini: pembedahan) adalah bagian
 * dari rekam medis, bukan data administratif pendaftaran. Nama kelas
 * Khanza-nya sendiri ("TagihanOperasi" — Tagihan = tagihan/billing)
 * menegaskan sisi keuangannya, makanya sinkron ke billing lewat
 * clinical.v_operation_charge dengan pola identik clinical.v_procedure_charge.
 *
 * Wave 1 SENGAJA menyederhanakan skema pembagian jasa: Khanza nyata
 * memecah honor per peran (bayar_operasi_dokter_anak/anestesi/pjanak/umum/
 * operator1 — lihat kolom "tabel terkait" baris 19 sheet2), satu tarif
 * lump-sum per tindakan operasi di sini, belum per-role. Pemecahan honor
 * tim bedah adalah perluasan wajar Wave berikutnya, bukan dianggap selesai.
 *
 * booking_operasi (jadwal operasi, encounter.operation_bookings) adalah
 * kode Khanza TERPISAH — janji jadwal sebelum operasi terjadi. Baris di
 * sini SENGAJA tidak mewajibkan booking_id: operasi cito/darurat sah
 * dicatat tanpa jadwal sebelumnya, sama seperti IGD tidak mewajibkan
 * booking_registrasi.
 */
return new class extends Migration
{
    private const S = 'clinical';

    public function up(): void
    {
        Schema::create(self::S . '.operations', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('patient_id');
            $table->string('registration_number', 24);
            $table->string('patient_mrn', 20);
            $table->string('patient_name', 150);

            $table->unsignedBigInteger('service_id')->comment('ID catalog.services kategori operasi, referensi longgar');
            $table->string('service_code', 40);
            $table->string('service_name', 200)->comment('Disalin saat pencatatan, bukan dirujuk — lihat catatan kelas');

            $table->decimal('amount', 14, 2)->comment('Tarif lump-sum, belum dipecah per peran tim bedah — lihat catatan kelas');

            $table->string('surgeon_name', 150)->comment('Operator utama — tidak selalu sama dengan dokter penanggung jawab kunjungan');
            $table->string('anesthesia_type', 20)->nullable()->comment('umum, lokal, regional, tanpa');
            $table->string('operating_room', 50)->nullable();
            $table->timestampTz('performed_at');
            $table->text('note')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampsTz();

            $table->index('registration_id');
            $table->index(['patient_id', 'performed_at']);
        });

        DB::statement("ALTER TABLE " . self::S . ".operations ADD CONSTRAINT operations_amount_check CHECK (amount >= 0)");
        DB::statement("ALTER TABLE " . self::S . ".operations ADD CONSTRAINT operations_anesthesia_check
            CHECK (anesthesia_type IS NULL OR anesthesia_type IN ('umum','lokal','regional','tanpa'))");

        // Kontrak baca untuk billing — pola sama dengan clinical.v_procedure_charge.
        DB::statement("CREATE VIEW " . self::S . ".v_operation_charge AS
            SELECT registration_id, id AS item_id, service_name, amount, performed_at
            FROM " . self::S . ".operations");
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS ' . self::S . '.v_operation_charge');
        Schema::dropIfExists(self::S . '.operations');
    }
};
