<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * tindakan_ralan (Khanza domain A, kelas DlgRawatJalan) — tercatat context=
 * encounter di katalog, tanpa penanda paket Java lain di
 * Khanza_Functional_Dependency_Map.xlsx (beda dari sekrining_rawat_jalan/
 * jadwal_praktek yang jelas menunjuk konteks lain). TAPI tetap dibangun di
 * clinical, bukan encounter: prosedur yang dilakukan ke pasien (ganti
 * verband, injeksi, jahit luka, dst.) adalah bagian dari rekam medis —
 * "apa yang terjadi ke pasien" — bukan data administratif pendaftaran,
 * sama semangatnya dengan diagnoses/observations yang sudah lebih dulu ada
 * di sini. billing.InvoiceService sejak awal punya komentar terbuka
 * "tindakan menyusul saat konteksnya digarap" — inilah itu.
 *
 * catalog.services.category sudah mengantisipasi 'tindakan' sejak skema
 * awal dibuat (lihat komentar kolomnya) tapi belum ada satu pun layanan
 * berkategori itu yang dicatat — cuma REG-RALAN (kategori registrasi).
 * Harga diambil dari catalog.tariffs seperti biaya registrasi, DISALIN ke
 * unit_price/amount saat dicatat (bukan dirujuk live) — konsisten dengan
 * pola diagnosis: tarif bisa direvisi, tindakan yang sudah tercatat dan
 * mungkin sudah tertagih tidak boleh ikut berubah.
 *
 * TIDAK bisa dihapus setelah dicatat (tidak seperti diagnoses/allergies
 * yang soft-delete) — beda dari keduanya, tindakan langsung disinkronkan
 * jadi baris tagihan lewat clinical.v_procedure_charge, dan billing.
 * charge_lines cuma menerima INSERT. Menghapus tindakan setelah tersinkron
 * akan meninggalkan baris tagihan basi yang tidak pernah terhapus balik —
 * koreksi harus lewat catatan/pembatalan tagihan di billing, bukan
 * menghapus baris klinisnya.
 */
return new class extends Migration
{
    private const S = 'clinical';

    public function up(): void
    {
        Schema::create(self::S . '.procedures', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('patient_id');
            $table->string('registration_number', 24);
            $table->string('patient_mrn', 20);
            $table->string('patient_name', 150);

            $table->unsignedBigInteger('service_id')->comment('ID catalog.services kategori tindakan, referensi longgar');
            $table->string('service_code', 40);
            $table->string('service_name', 200)->comment('Disalin saat pencatatan, bukan dirujuk — lihat catatan kelas');

            $table->decimal('quantity', 8, 2)->default(1);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('amount', 14, 2)->comment('quantity * unit_price pada saat dicatat');

            $table->unsignedBigInteger('practitioner_id')->nullable();
            $table->string('practitioner_name', 150)->nullable();
            $table->timestampTz('performed_at');
            $table->text('note')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampsTz();

            $table->index('registration_id');
            $table->index(['patient_id', 'performed_at']);
        });

        DB::statement("ALTER TABLE " . self::S . ".procedures ADD CONSTRAINT procedures_amount_check
            CHECK (quantity > 0 AND unit_price >= 0 AND amount >= 0)");

        // Kontrak baca untuk billing — pola sama dengan pharmacy.v_prescription_charge
        // dan orders.v_order_charge yang sudah ada.
        DB::statement("CREATE VIEW " . self::S . ".v_procedure_charge AS
            SELECT registration_id, id AS item_id, service_name, quantity, unit_price, amount, performed_at
            FROM " . self::S . ".procedures");
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS ' . self::S . '.v_procedure_charge');
        Schema::dropIfExists(self::S . '.procedures');
    }
};
