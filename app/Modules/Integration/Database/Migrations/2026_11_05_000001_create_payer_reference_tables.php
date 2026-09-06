<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Referensi & pemetaan kode penjamin (domain L item E) — ~60 kode.
 *
 * Menaungi seluruh bpjs_referensi_*, bpjs_cek_* yang berupa daftar
 * referensi (propinsi, kabupaten, kecamatan, dokter, poli, kelas rawat,
 * ruang rawat, cara keluar, pasca pulang, spesialistik, prosedur,
 * diagnosa, faskes), referensi HFIS, referensi apotek BPJS, seluruh
 * inhealth_referensi_*, serta pemetaan mapping_poli_bpjs,
 * bpjs_mapping_dokterdpjp, bpjs_mapping_obat_apotek, dan
 * inhealth_mapping_*.
 *
 * DUA BENTUK YANG BERULANG PULUHAN KALI, jadi dua tabel — bukan enam
 * puluh. Khanza memberi satu menu per daftar referensi dan satu menu per
 * pemetaan; yang berbeda cuma ISI daftarnya, bukan bentuk datanya
 * maupun cara memakainya.
 *
 * REFERENSI ADALAH SALINAN, BUKAN MASTER. Daftar poli, dokter, dan faskes
 * di sini milik BPJS/Inhealth — disimpan supaya bisa dicari cepat dan
 * tetap terbaca saat API mereka sedang mati, tapi TIDAK PERNAH menjadi
 * sumber kebenaran kita sendiri. Master unit dan praktisi tetap di
 * konteks organization. Karena itu ada kolom fetched_at: referensi yang
 * basi harus terlihat basi, bukan tampak sahih selamanya.
 *
 * PEMETAAN PENJAMIN DIPISAH DARI PEMETAAN SATUSEHAT (item D) dengan alasan
 * yang jelas: pemetaan SATUSEHAT menunjuk TERMINOLOGI STANDAR (SNOMED,
 * LOINC, KFA) dan wajib menyebut sistem kodenya; pemetaan penjamin
 * menunjuk kode milik penjamin itu sendiri, yang tidak punya sistem kode
 * eksternal. Menyatukannya memaksa kolom code_system yang wajib untuk
 * separuh baris dan tidak berarti apa-apa untuk separuh lainnya — batasan
 * yang berlaku setengah adalah batasan yang cepat atau lambat dilanggar
 * tanpa ada yang menyadarinya.
 */
return new class extends Migration
{
    private const S = 'integration';

    private const PENJAMIN = ['bpjs', 'bpjs-apotek', 'hfis', 'inhealth'];

    public function up(): void
    {
        Schema::create(self::S . '.payer_references', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('payer', 20)->comment('bpjs / bpjs-apotek / hfis / inhealth');
            $table->string('reference_type', 40)->comment('poli, dokter, faskes, propinsi, kelas-rawat, dst.');

            $table->string('code', 40);
            $table->string('name', 250);

            // Referensi berjenjang (kabupaten di bawah propinsi, kecamatan
            // di bawah kabupaten) memakai kolom ini alih-alih tabel
            // terpisah per jenjang.
            $table->string('parent_code', 40)->nullable();

            $table->json('raw')->nullable()->comment('Jawaban mentah, untuk kolom yang belum terpakai');

            // Referensi yang basi harus TERLIHAT basi.
            $table->timestampTz('fetched_at');

            $table->timestampsTz();

            $table->unique(['payer', 'reference_type', 'code'], 'payer_reference_unik');
            $table->index(['payer', 'reference_type', 'parent_code']);
            $table->index('name');
        });

        DB::statement('ALTER TABLE ' . self::S . ".payer_references
            ADD CONSTRAINT payer_references_payer_check
            CHECK (payer IN ('" . implode("','", self::PENJAMIN) . "'))");

        Schema::create(self::S . '.payer_code_mappings', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('payer', 20);
            $table->string('mapping_type', 40)->comment('poli, dokter, dokter-dpjp, obat, tindakan-ralan, dst.');

            $table->string('local_code', 40);
            $table->string('local_name', 200)->nullable();

            // Kode milik penjamin. Kosong = belum dipetakan, dan itu
            // keadaan sah — bukan sesuatu yang boleh ditebak.
            $table->string('payer_code', 60)->nullable();
            $table->string('payer_name', 250)->nullable();

            $table->boolean('is_active')->default(true);

            $table->unsignedBigInteger('mapped_by')->nullable();
            $table->timestampTz('mapped_at')->nullable();
            $table->timestampsTz();

            $table->unique(['payer', 'mapping_type', 'local_code'], 'payer_mapping_unik');
            $table->index(['payer', 'mapping_type', 'payer_code']);
        });

        DB::statement('ALTER TABLE ' . self::S . ".payer_code_mappings
            ADD CONSTRAINT payer_code_mappings_payer_check
            CHECK (payer IN ('" . implode("','", self::PENJAMIN) . "'))");

        // Satu kode penjamin tidak boleh dipakai dua kode lokal pada jenis
        // yang sama: klaim untuk poli A akan terkirim sebagai poli B, dan
        // yang salah tidak akan terlihat sampai klaimnya ditolak.
        DB::statement('CREATE UNIQUE INDEX payer_mapping_kode_penjamin_unik
            ON ' . self::S . '.payer_code_mappings (payer, mapping_type, payer_code)
            WHERE payer_code IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.payer_code_mappings');
        Schema::dropIfExists(self::S . '.payer_references');
    }
};
