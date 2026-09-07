<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data induk kecelakaan & penjaminan Jasa Raharja (domain L item K).
 *
 * Menaungi bpjs_data_induk_kecelakaan, bpjs_suplesi_jasaraharja, dan
 * bpjs_klaim_jasa_raharja.
 *
 * DUDUK PERKARANYA: korban kecelakaan lalu lintas di Indonesia dijamin
 * BERJENJANG — PT Jasa Raharja menanggung lebih dulu sampai batas
 * santunannya, dan BPJS menanggung selisihnya (Perpres 82/2018 dan
 * kesepakatan koordinasi manfaat keduanya). Urutan itu ditetapkan
 * peraturan, bukan pilihan rumah sakit. Salah mencatat jenis kejadiannya
 * berarti menagih ke penjamin yang salah, dan yang menanggung akibatnya
 * pada akhirnya pasien atau rumah sakit.
 *
 * SATU KEJADIAN, BANYAK KUNJUNGAN. Korban yang sama kembali kontrol
 * berkali-kali untuk kecelakaan yang SAMA. Karena itu kejadiannya dicatat
 * sekali di sini, dan kunjungan berikutnya menunjuknya lewat SUPLESI —
 * bukan mencatat kecelakaan baru tiap kali pasien datang. Kalau tiap
 * kunjungan jadi kejadian baru, satu kecelakaan berlipat menjadi banyak
 * dalam statistik nasional, dan pelacakan batas santunan Jasa Raharja
 * yang bersandar pada satu kejadian ikut kacau.
 *
 * TANGGAL KEJADIAN TIDAK BOLEH SETELAH TANGGAL PELAYANAN. Terdengar
 * sepele, tapi salah ketik tahun pada tanggal kejadian adalah kesalahan
 * yang paling sering terjadi dan paling sulit terlihat: SEP tetap terbit,
 * lalu klaimnya ditolak berbulan-bulan kemudian.
 *
 * PENJAMINAN JASA RAHARJA DICATAT SEBAGAI JAWABAN MEREKA, bukan sebagai
 * keputusan kita. Berapa yang ditanggung dan sampai kapan ditentukan Jasa
 * Raharja; yang kita simpan adalah salinan jawabannya berikut kapan
 * ditanyakan — supaya perbedaan antara "belum ditanyakan" dan "sudah
 * ditanyakan, tidak dijamin" tidak pernah kabur.
 */
return new class extends Migration
{
    private const S = 'integration';

    /**
     * Jenis kejadian menurut VClaim. Bukan daftar karangan: keempatnya
     * menentukan siapa penjamin pertamanya.
     */
    private const JENIS = ['kll', 'kll-kerja', 'kerja', 'lainnya'];

    private const STATUS = ['dicatat', 'dijamin', 'tidak-dijamin', 'batal'];

    public function up(): void
    {
        Schema::create(self::S . '.accident_records', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('patient_id');
            $table->string('patient_mrn', 30);
            $table->string('patient_name', 150);
            $table->string('card_number', 20)->nullable();

            // Kunjungan tempat kejadian ini PERTAMA kali dicatat. Kunjungan
            // berikutnya menunjuk kejadian ini lewat suplesi, bukan membuat
            // baris baru — lihat catatan di atas.
            $table->unsignedBigInteger('registration_id')->nullable();
            $table->string('sep_number', 30)->nullable()->comment('SEP pertama atas kejadian ini');

            $table->string('accident_type', 20)->default('kll');
            $table->date('occurred_on');
            $table->time('occurred_at_time')->nullable();

            // Lokasi berjenjang memakai kode wilayah BPJS, bukan teks bebas:
            // teks bebas membuat laporan kecelakaan per wilayah mustahil
            // disusun dan ditolak VClaim saat SEP diterbitkan.
            $table->string('province_code', 10)->nullable();
            $table->string('regency_code', 10)->nullable();
            $table->string('district_code', 10)->nullable();
            $table->string('location_note', 300)->nullable();

            $table->string('status', 20)->default('dicatat');

            // Jawaban Jasa Raharja — salinan, bukan keputusan kita.
            $table->boolean('jasa_raharja_asked')->default(false);
            $table->timestampTz('jasa_raharja_asked_at')->nullable();
            $table->boolean('jasa_raharja_covered')->nullable()
                ->comment('null = belum ditanyakan; false = sudah ditanyakan dan TIDAK dijamin');
            $table->decimal('jasa_raharja_ceiling', 15, 2)->nullable();
            $table->string('jasa_raharja_number', 40)->nullable();
            $table->date('jasa_raharja_valid_until')->nullable();
            $table->json('jasa_raharja_response')->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestampsTz();

            $table->index(['patient_id', 'occurred_on']);
            $table->index('card_number');
            $table->index(['status', 'occurred_on']);
        });

        DB::statement('ALTER TABLE ' . self::S . ".accident_records
            ADD CONSTRAINT accident_records_type_check
            CHECK (accident_type IN ('" . implode("','", self::JENIS) . "'))");

        DB::statement('ALTER TABLE ' . self::S . ".accident_records
            ADD CONSTRAINT accident_records_status_check
            CHECK (status IN ('" . implode("','", self::STATUS) . "'))");

        // Kunjungan lanjutan atas kejadian yang sama.
        Schema::create(self::S . '.accident_supplements', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('accident_record_id')->constrained(self::S . '.accident_records');

            $table->unsignedBigInteger('registration_id');
            $table->string('sep_number', 30)->nullable();
            $table->date('service_date');

            $table->string('status', 20)->default('diajukan');
            $table->string('response_code', 10)->nullable();
            $table->string('response_message', 300)->nullable();
            $table->json('raw_response')->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestampsTz();

            // Satu kunjungan hanya boleh menunjuk satu kejadian: dua suplesi
            // untuk kunjungan yang sama akan ditagihkan dua kali ke Jasa
            // Raharja atas pelayanan yang sama.
            $table->unique(['registration_id'], 'accident_supplement_registration_unique');
            $table->index('accident_record_id');
        });

        DB::statement('ALTER TABLE ' . self::S . ".accident_supplements
            ADD CONSTRAINT accident_supplements_status_check
            CHECK (status IN ('diajukan','diterima','gagal','batal'))");
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.accident_supplements');
        Schema::dropIfExists(self::S . '.accident_records');
    }
};
