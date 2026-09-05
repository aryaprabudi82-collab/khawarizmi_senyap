<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Domain I item A. Dua sumber biaya baru sekaligus, karena keduanya
 * menambah nilai yang sama pada charge_lines.source_type:
 *
 *  - 'kamar'       : biaya kamar rawat inap, satu baris per hari
 *    menginap, dibaca dari inpatient.v_room_charge (kode pembayaran_ranap).
 *  - 'penyesuaian' : tambahan & potongan biaya yang diketik kasir
 *    (kode tambahan_biaya dan potongan_biaya).
 *
 * Penyesuaian manual dapat tabel pendamping sendiri, tidak langsung
 * ditulis ke charge_lines (dikonfirmasi user). Alasannya: charge_lines
 * sengaja idempoten terhadap (source_type, source_id) dari peristiwa
 * sumbernya, dan penyesuaian manual tidak punya peristiwa sumber di
 * konteks lain — tabel ini yang jadi peristiwanya. Sekalian ia menyimpan
 * siapa yang menambah/memotong, berapa, dan alasannya: ini satu-satunya
 * biaya di seluruh sistem yang diketik manusia, jadi justru yang paling
 * perlu jejak audit.
 *
 * Potongan disimpan sebagai amount NEGATIF, bukan kolom/tabel terpisah,
 * supaya penjumlahan total tagihan tetap satu operasi yang sama.
 * Khanza sendiri tidak punya tabel untuk potongan_biaya (tabel `potongan`
 * miliknya adalah potongan gaji pegawai — bpjs/jamsostek/dansos per
 * bulan, sama sekali bukan tagihan pasien), jadi bentuknya ditentukan di
 * sini sebagai cerminan tambahan_biaya, yang di Khanza memang cuma
 * no_rawat + nama_biaya + besar_biaya.
 */
return new class extends Migration
{
    private const S = 'billing';

    public function up(): void
    {
        /*
         * Khanza memberi kode berbeda untuk kasir rawat jalan
         * (pembayaran_ralan) dan rawat inap (pembayaran_ranap) — di rumah
         * sakit sungguhan keduanya memang sering loket dan orang yang
         * berbeda. Satu layar tagihan di sini melayani keduanya, jadi
         * tagihan perlu tahu ia jenis rawat apa untuk bisa menentukan
         * permission mana yang berlaku. Disalin dari
         * encounter.v_registration_summary, sama seperti salinan
         * patient_name/unit_name/payer_name yang sudah ada.
         */
        Schema::table(self::S . '.invoices', function (Blueprint $table) {
            $table->string('care_type', 20)->default('ralan')->after('unit_name')
                ->comment('ralan atau ranap — menentukan gerbang pembayaran_ralan vs pembayaran_ranap');
        });

        Schema::create(self::S . '.manual_adjustments', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('invoice_id')->constrained(self::S . '.invoices')->cascadeOnDelete();

            $table->string('kind', 12)->comment('tambahan atau potongan');
            $table->string('description', 100)->comment('Padanan nama_biaya Khanza');
            $table->decimal('amount', 14, 2)->comment('Positif untuk tambahan, negatif untuk potongan');
            $table->text('reason')->nullable();

            $table->unsignedBigInteger('created_by')->comment('ID pengguna platform, referensi longgar');
            $table->timestampTz('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->text('void_reason')->nullable();

            $table->timestampsTz();

            $table->index(['invoice_id', 'voided_at']);
        });

        DB::statement('ALTER TABLE ' . self::S . ".manual_adjustments ADD CONSTRAINT manual_adjustments_kind_check
            CHECK (kind IN ('tambahan','potongan'))");

        // Tanda dan jenis harus sejalan: tambahan tidak boleh mengurangi
        // tagihan, potongan tidak boleh menambah. Nol dilarang keduanya —
        // penyesuaian tanpa nilai cuma bikin baris kosong di rincian.
        DB::statement('ALTER TABLE ' . self::S . ".manual_adjustments ADD CONSTRAINT manual_adjustments_sign_check
            CHECK ((kind = 'tambahan' AND amount > 0) OR (kind = 'potongan' AND amount < 0))");

        DB::statement('ALTER TABLE ' . self::S . '.charge_lines DROP CONSTRAINT charge_lines_source_check');
        DB::statement('ALTER TABLE ' . self::S . ".charge_lines ADD CONSTRAINT charge_lines_source_check
            CHECK (source_type IN ('registrasi','resep_obat','order_penunjang','tindakan_ralan','operasi','kamar','penyesuaian'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ' . self::S . '.charge_lines DROP CONSTRAINT charge_lines_source_check');
        DB::statement('ALTER TABLE ' . self::S . ".charge_lines ADD CONSTRAINT charge_lines_source_check
            CHECK (source_type IN ('registrasi','resep_obat','order_penunjang','tindakan_ralan','operasi'))");

        Schema::dropIfExists(self::S . '.manual_adjustments');

        Schema::table(self::S . '.invoices', function (Blueprint $table) {
            $table->dropColumn('care_type');
        });
    }
};
