<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Skrining IMLTD & look-back (domain N item A).
 *
 * DI SINI YANG KURANG ADALAH KITA, BUKAN KHANZA — dan itu perlu
 * disebut terang-terangan. utd_donor Khanza mencatat lima hasil
 * skrining pada setiap donasi: HBsAg, anti-HCV, anti-HIV, sifilis, dan
 * malaria. Modul blood di sini punya siklus status yang lebih rapi
 * (karantina, ditahan, ditolak, dipisahkan) tapi release() memindahkan
 * unit ke "tersedia" dengan alasan teks bebas belaka — unit dinyatakan
 * lolos skrining tanpa satu pun bukti skriningnya.
 *
 * Audit 2026-10 menyimpulkan utd_donor "sudah cukup terpenuhi lewat
 * event collect() sebagai catatan donasi". Kesimpulan itu keliru:
 * yang menjadikan darah aman bukan peristiwa pengambilannya melainkan
 * pemeriksaan setelahnya, dan tanpa hasil pemeriksaan yang tercatat,
 * status "tersedia" cuma pernyataan tanpa dasar.
 *
 * IMLTD — Infeksi Menular Lewat Transfusi Darah — adalah istilah resmi
 * Indonesia untuk kelima pemeriksaan itu, dan kelimanya WAJIB pada
 * setiap kantong sebelum boleh dikeluarkan.
 *
 * SKRINING MELEKAT PADA UNIT, BUKAN PADA DONOR. Berbeda dari serologi
 * pasien dialisis (domain M item P) yang memang fakta tingkat pasien
 * berlaku enam bulan: hasil skrining darah berlaku untuk KANTONG ITU
 * SAJA. Donor yang bersih bulan lalu bisa terinfeksi minggu ini, dan
 * memakai hasil lama untuk kantong baru persis cara darah terinfeksi
 * lolos ke pasien. Perbedaan ini disengaja, bukan ketidakkonsistenan.
 *
 * SATU HASIL REAKTIF MENOLAK KANTONGNYA, dan tidak ada jalan memutar:
 * ditegakkan service DAN basis data.
 *
 * LOOK-BACK ADALAH ALASAN KETERLACAKAN ITU ADA. Ketika seorang donor
 * kemudian diketahui reaktif, seluruh kantong yang pernah berasal
 * darinya — termasuk komponen hasil pemisahan, cucu dari donasi itu —
 * harus ditemukan dan ditarik, dan pasien yang sudah menerimanya harus
 * bisa disebutkan. Tautan datanya sudah ada sejak awal (donor_id dan
 * parent_unit_id); yang belum ada operasinya, dan tabel ini yang
 * mencatat penelusurannya supaya penarikan punya jejak.
 */
return new class extends Migration
{
    private const S = 'blood';

    public function up(): void
    {
        $this->createScreenings();
        $this->createLookbacks();
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S.'.lookback_investigations');
        Schema::dropIfExists(self::S.'.unit_screenings');
    }

    private function createScreenings(): void
    {
        Schema::create(self::S.'.unit_screenings', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('blood_unit_id')->constrained(self::S.'.blood_units');

            // Kelima pemeriksaan IMLTD. Tidak ada yang boleh kosong saat
            // unit dirilis — itulah gunanya kolomnya tidak nullable.
            $table->string('hbsag', 20);
            $table->string('anti_hcv', 20);
            $table->string('anti_hiv', 20);
            $table->string('syphilis', 20);
            $table->string('malaria', 20);

            $table->string('method', 60)->nullable()->comment('Rapid, ELISA, CHLIA, NAT');
            $table->timestampTz('screened_at');
            $table->unsignedBigInteger('screened_by')->nullable();
            $table->string('screened_by_name', 150);

            $table->boolean('is_repeat')->default(false)
                ->comment('Pemeriksaan ulang atas hasil meragukan; yang lama tetap ada');
            $table->text('note')->nullable();

            $table->timestampsTz();

            $table->index(['blood_unit_id', 'screened_at']);
        });

        foreach (['hbsag', 'anti_hcv', 'anti_hiv', 'syphilis', 'malaria'] as $kolom) {
            DB::statement('ALTER TABLE '.self::S.".unit_screenings
                ADD CONSTRAINT unit_screenings_{$kolom}_check
                CHECK ({$kolom} IN ('non-reaktif','reaktif','meragukan'))");
        }

        // Satu pemeriksaan per unit per waktu; pemeriksaan ulang punya
        // waktunya sendiri dan tidak menimpa yang pertama.
        DB::statement('CREATE UNIQUE INDEX unit_screenings_time_unique
            ON '.self::S.'.unit_screenings (blood_unit_id, screened_at)');
    }

    private function createLookbacks(): void
    {
        Schema::create(self::S.'.lookback_investigations', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('investigation_number', 24)->unique();
            $table->foreignId('donor_id')->constrained(self::S.'.donors');

            $table->timestampTz('triggered_at');
            $table->string('trigger', 40)
                ->comment('skrining-reaktif, laporan-pasien, laporan-donor, temuan-lain');
            $table->text('trigger_detail');

            // Dibekukan saat penelusuran dijalankan: jumlah kantong yang
            // ditemukan dan yang sudah terlanjur dikeluarkan ke pasien.
            $table->unsignedSmallInteger('units_found')->default(0);
            $table->unsignedSmallInteger('units_recalled')->default(0);
            $table->unsignedSmallInteger('units_already_issued')->default(0);
            $table->jsonb('unit_numbers')->default(DB::raw("'[]'::jsonb"));
            $table->jsonb('affected_patients')->default(DB::raw("'[]'::jsonb"))
                ->comment('Pasien yang sudah terlanjur menerima; merekalah yang harus dihubungi');

            $table->text('conclusion')->nullable();
            $table->string('status', 20)->default('berjalan')->comment('berjalan, selesai');
            $table->timestampTz('closed_at')->nullable();

            $table->unsignedBigInteger('opened_by')->nullable();
            $table->string('opened_by_name', 150);

            $table->timestampsTz();

            $table->index(['donor_id', 'triggered_at']);
            $table->index(['status', 'triggered_at']);
        });

        DB::statement('ALTER TABLE '.self::S.".lookback_investigations
            ADD CONSTRAINT lookback_investigations_trigger_check
            CHECK (trigger IN ('skrining-reaktif','laporan-pasien','laporan-donor','temuan-lain'))");

        DB::statement('ALTER TABLE '.self::S.".lookback_investigations
            ADD CONSTRAINT lookback_investigations_status_check
            CHECK (status IN ('berjalan','selesai'))");

        // Penelusuran yang ditutup wajib menyimpulkan sesuatu: penarikan
        // darah yang berhenti tanpa kesimpulan tidak bisa dibuktikan
        // pernah dituntaskan.
        DB::statement('ALTER TABLE '.self::S.".lookback_investigations
            ADD CONSTRAINT lookback_investigations_closed_check
            CHECK (status <> 'selesai'
                   OR (closed_at IS NOT NULL
                       AND conclusion IS NOT NULL AND btrim(conclusion) <> ''))");

        // Yang ditarik tidak mungkin lebih banyak daripada yang ditemukan.
        DB::statement('ALTER TABLE '.self::S.'.lookback_investigations
            ADD CONSTRAINT lookback_investigations_count_check
            CHECK (units_recalled + units_already_issued <= units_found)');
    }
};
