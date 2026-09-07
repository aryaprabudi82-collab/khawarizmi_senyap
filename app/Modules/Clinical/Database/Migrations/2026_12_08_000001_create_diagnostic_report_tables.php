<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hasil pemeriksaan penunjang khusus (domain M item G).
 *
 * Menaungi 18 kode: hasil_pemeriksaan_ekg, _echo, _echo_pediatrik, _oct,
 * _slit_lamp, _treadmill, _usg, _usg_abdomen, hasil_usg_gynecologi,
 * _usg_neonatus, _usg_urologi, hasil_endoskopi_telinga, _hidung,
 * _faring_laring, hasil_tindakan_eswl, layanan_kedokteran_fisik_rehabilitasi,
 * uji_fungsi_kfr, dan penatalaksanaan_terapi_okupasi.
 *
 * DIPERIKSA KE SKEMA KHANZA. Kedelapan belas tabelnya berbagi kerangka
 * yang sama — no_rawat, tanggal, dokter pemeriksa, diagnosa klinis,
 * kiriman dari, dan kesimpulan — lalu masing-masing punya butir khas
 * modalitasnya: EKG punya irama, laju jantung, gelombang P, interval PR,
 * aksis, kompleks QRS, segmen ST; USG kandungan punya kantong gestasi,
 * diameter biparietal, panjang femur, tafsiran berat janin.
 *
 * BUTIRNYA JADI TEMPLATE, KERANGKANYA JADI TABEL. Butir khas modalitas
 * persis bentuk yang sudah ditangani template formulir sejak item A —
 * pertanyaan yang berbeda-beda per jenis. Yang tidak bisa dititipkan ke
 * form_responses adalah kerangkanya: hasil pemeriksaan punya KESIMPULAN,
 * punya PEMERIKSA yang berbeda dari pencatat, dan boleh menunjuk
 * permintaan penunjang. Menambahkan ketiganya ke form_responses akan
 * membebani seluruh formulir lain dengan kolom yang tidak mereka pakai.
 *
 * KESIMPULAN WAJIB DIISI SAAT DIFINALKAN, dan ini pengetatan terhadap
 * Khanza yang membiarkannya kosong. Hasil pemeriksaan tanpa kesimpulan
 * adalah kumpulan angka yang tidak ditafsirkan siapa pun — dan yang
 * membaca berikutnya adalah dokter lain yang menganggap tidak adanya
 * kesimpulan berarti tidak ada temuan.
 *
 * PEMERIKSA DICATAT TERPISAH DARI PENCATAT. Dokter yang membaca EKG
 * bertanggung jawab atas tafsirannya; yang mengetiknya bisa orang lain.
 * Menyatukan keduanya membuat tanggung jawab tafsiran klinis melekat pada
 * siapa pun yang kebetulan memegang papan ketik.
 *
 * BOLEH MENUNJUK PERMINTAAN PENUNJANG, TIDAK WAJIB. EKG di samping tempat
 * tidur pada pasien yang mendadak nyeri dada tidak melewati sistem
 * permintaan, dan mewajibkannya berarti pemeriksaan yang paling mendesak
 * justru yang tidak bisa dicatat.
 */
return new class extends Migration
{
    private const S = 'clinical';

    public function up(): void
    {
        Schema::create(self::S . '.diagnostic_reports', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('patient_id');
            $table->string('registration_number', 30);
            $table->string('patient_mrn', 30);
            $table->string('patient_name', 150);

            // Permintaan penunjang yang mendasarinya, bila memang lewat
            // sistem permintaan. Tanpa foreign key lintas schema.
            $table->unsignedBigInteger('order_id')->nullable();

            // Template yang dipakai, DAN versinya — dibekukan sama seperti
            // form_responses: pertanyaan yang dijawab tidak boleh berubah
            // saat templatenya direvisi.
            $table->string('template_code', 60);
            $table->unsignedSmallInteger('template_version');
            $table->string('template_name', 150);
            $table->boolean('template_approved')->default(false);

            $table->string('modality', 40)->nullable()->comment('ekg, usg, echo, endoskopi, oct, treadmill, eswl, kfr');

            $table->string('clinical_diagnosis', 200)->nullable();
            $table->string('referred_from', 100)->nullable()->comment('Poli atau dokter pengirim');

            $table->json('findings')->comment('Butir khas modalitas, mengikuti templatenya');

            // Kesimpulan pemeriksa — wajib saat difinalkan, lihat catatan.
            $table->text('conclusion')->nullable();
            $table->text('recommendation')->nullable();

            // Pemeriksa: yang bertanggung jawab atas tafsirannya.
            $table->unsignedBigInteger('performed_by_practitioner_id')->nullable();
            $table->string('performed_by_name', 150)->nullable();
            $table->timestampTz('performed_at');

            $table->string('status', 20)->default('draf');

            // Pencatat: yang mengetiknya, bisa berbeda dari pemeriksa.
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('recorded_by_name', 150)->nullable();
            $table->timestampTz('finalized_at')->nullable();
            $table->unsignedBigInteger('finalized_by')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['registration_id', 'template_code']);
            $table->index(['patient_id', 'modality']);
            $table->index('order_id');
        });

        DB::statement('ALTER TABLE ' . self::S . ".diagnostic_reports
            ADD CONSTRAINT diagnostic_reports_status_check
            CHECK (status IN ('draf','final','dibatalkan'))");

        // Yang final wajib punya kesimpulan DAN waktu finalisasi. Hasil
        // tanpa kesimpulan adalah angka yang tidak ditafsirkan siapa pun,
        // dan pembacanya akan menganggap tidak ada temuan.
        DB::statement('ALTER TABLE ' . self::S . ".diagnostic_reports
            ADD CONSTRAINT diagnostic_reports_final_check
            CHECK (status <> 'final'
                   OR (finalized_at IS NOT NULL
                       AND conclusion IS NOT NULL
                       AND btrim(conclusion) <> ''))");
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.diagnostic_reports');
    }
};
