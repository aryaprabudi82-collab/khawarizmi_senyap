<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alur transaksi lab kesling (Khanza domain B, sisa 6 kode setelah data
 * master). Ditemukan lewat pembacaan permissions.json, bukan cuma nama
 * access-flag-nya sendiri: access_flag SATU LANGKAH gates layar BERIKUTNYA
 * (mis. kode "hasil_pengujian..." gerbangnya layar "Data Penugasan", kode
 * "verifikasi_pengujian..." gerbangnya layar "Data Hasil") — pola
 * segregation-of-duties ala akreditasi laboratorium (ISO 17025): yang
 * menguji bukan yang memverifikasi, yang memverifikasi bukan yang
 * memvalidasi akhir. Pemetaan sungguhannya, tiap access flag dengan
 * layar+peran yang benar-benar digerbanginya:
 *
 *   permintaan_pengujian_sampel_lab_kesehatan_lingkungan
 *     -> loket: buat permintaan + tolak sampel tidak layak (satu kode
 *        untuk dua aksi, sama seperti baris sheet2 41-42 berbagi access
 *        flag yang sama persis)
 *   penugasan_pengujian_sampel_lab_kesehatan_lingkungan
 *     -> penyelia: terima sampel + tugaskan ke analis (satu aksi gabungan,
 *        bukan dua langkah terpisah, sederhana ala Wave 1)
 *   hasil_pengujian_sampel_lab_kesehatan_lingkungan
 *     -> analis: lihat antrean tugas + entri hasil pengujian
 *   verifikasi_pengujian_sampel_lab_kesehatan_lingkungan
 *     -> penyelia: lihat hasil mentah + verifikasi
 *   validasi_pengujian_sampel_lab_kesehatan_lingkungan
 *     -> penyelia: lihat data terverifikasi + validasi akhir (Wave 1
 *        menggabung penyelia verifikasi+validasi jadi satu peran
 *        penyelia-lab-kesling, bukan dua peran berbeda seperti akreditasi
 *        sungguhan biasanya memisahkan)
 *
 * rekap_pelayanan_lab_kesehatan_lingkungan dan pembayaran_pengujian_
 * sampel_lab_kesehatan_lingkungan/rekap_pembayaran... TIDAK terintegrasi ke
 * billing/finance — pelanggan lab kesling belum tentu pasien/registrasi,
 * jadi pembayaran dicatat sendiri di sini (payment_status), bukan lewat
 * billing.invoices yang berpusat pada kunjungan pasien.
 */
return new class extends Migration
{
    private const S = 'envlab';

    public function up(): void
    {
        $this->createSampleTests();
        $this->createSampleTestItems();
        $this->createSequences();
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.number_sequences');
        Schema::dropIfExists(self::S . '.sample_test_items');
        Schema::dropIfExists(self::S . '.sample_tests');
    }

    private function createSampleTests(): void
    {
        Schema::create(self::S . '.sample_tests', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('request_number', 24)->unique();

            $table->unsignedBigInteger('customer_id');
            $table->string('customer_name', 150);
            $table->unsignedBigInteger('sample_type_id');
            $table->string('sample_type_name', 150);
            $table->string('sample_description', 255)->nullable();

            $table->string('status', 20)->default('diminta');
            $table->string('rejection_reason', 255)->nullable();

            $table->unsignedBigInteger('requested_by')->nullable();
            $table->string('requested_by_name', 150)->nullable();
            $table->timestampTz('requested_at');

            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->string('assigned_to_name', 150)->nullable();
            $table->timestampTz('assigned_at')->nullable();

            $table->unsignedBigInteger('verified_by')->nullable();
            $table->string('verified_by_name', 150)->nullable();
            $table->timestampTz('verified_at')->nullable();

            $table->unsignedBigInteger('validated_by')->nullable();
            $table->string('validated_by_name', 150)->nullable();
            $table->timestampTz('validated_at')->nullable();

            $table->decimal('price', 14, 2)->nullable();
            $table->string('payment_status', 20)->default('belum-bayar');
            $table->timestampTz('paid_at')->nullable();

            $table->timestampsTz();

            $table->foreign('customer_id')->references('id')->on('envlab.customers');
            $table->foreign('sample_type_id')->references('id')->on('envlab.sample_types');
            $table->index(['status', 'requested_at']);
        });

        DB::statement("ALTER TABLE " . self::S . ".sample_tests ADD CONSTRAINT sample_tests_status_check
            CHECK (status IN ('diminta','ditolak','diproses','hasil-tersedia','terverifikasi','selesai','dibatalkan'))");
        DB::statement("ALTER TABLE " . self::S . ".sample_tests ADD CONSTRAINT sample_tests_payment_status_check
            CHECK (payment_status IN ('belum-bayar','lunas'))");
    }

    private function createSampleTestItems(): void
    {
        Schema::create(self::S . '.sample_test_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('sample_test_id')->constrained(self::S . '.sample_tests')->cascadeOnDelete();

            $table->unsignedBigInteger('parameter_id');
            $table->string('parameter_name', 150);
            $table->string('unit', 30)->nullable();

            // Disalin dari envlab.quality_standards saat permintaan dibuat —
            // baku mutu bisa direvisi kemudian, hasil yang sudah tercatat
            // tidak boleh ikut berubah maknanya. Pola sama dengan
            // orders.order_items menyalin rentang rujukan saat order dibuat.
            $table->decimal('standard_min', 12, 4)->nullable();
            $table->decimal('standard_max', 12, 4)->nullable();
            $table->string('standard_qualitative', 100)->nullable();

            $table->decimal('result_value', 12, 4)->nullable();
            $table->string('result_text', 200)->nullable();
            $table->boolean('is_exceeded')->default(false);

            $table->unsignedBigInteger('entered_by')->nullable();
            $table->string('entered_by_name', 150)->nullable();
            $table->timestampTz('entered_at')->nullable();

            $table->timestampsTz();

            $table->foreign('parameter_id')->references('id')->on('envlab.test_parameters');
            $table->unique(['sample_test_id', 'parameter_id']);
        });
    }

    private function createSequences(): void
    {
        Schema::create(self::S . '.number_sequences', function (Blueprint $table) {
            $table->string('prefix', 16)->primary();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestampTz('updated_at')->nullable();
        });
    }
};
