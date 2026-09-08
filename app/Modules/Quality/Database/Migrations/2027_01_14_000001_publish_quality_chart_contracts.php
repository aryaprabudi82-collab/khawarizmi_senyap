<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kontrak grafik mutu & keselamatan (domain O item B).
 *
 * Menaungi 5 kode grafik IKP (grafik_ikp_pertahun, _perbulan,
 * _pertanggal, _jenis, _dampak) dan 10 kode grafik K3.
 *
 * SEMBILAN KODE HAIs SENGAJA TIDAK DIBUATKAN GRAFIK BARU. Domain J item
 * E sudah membangun HaisSurveillanceService lengkap dengan
 * ratesByType(), ratesByUnit(), dailyEvents(), dan monthlyEvents() —
 * dan ratesByUnit($dari, $sampai, 'vap') PERSIS grafik_HAIs_laju_vap.
 * Menghitung ulang lajunya di konteks reporting akan melahirkan sumber
 * kedua bagi angka infeksi, dan dua angka laju infeksi yang berbeda
 * untuk bangsal yang sama adalah keadaan yang jauh lebih buruk daripada
 * satu grafik yang harus dibuka di layar lain.
 *
 * JENIS LUKA DITAMBAHKAN, dan itu lubang nyata yang baru ketahuan saat
 * membandingkan dengan skema Khanza. k3rs_peristiwa membedakan
 * kode_cidera (JENIS CIDERA — mekanismenya: terjatuh, tertusuk,
 * terpapar) dari kode_luka (JENIS LUKA — akibatnya: lecet, memar,
 * patah). Tabel k3_incidents di sini cuma punya injury_type, sehingga
 * grafik_k3_perjenisluka tidak punya kolom sama sekali. Keduanya
 * memang pertanyaan yang berbeda: satu menunjuk apa yang harus dicegah,
 * satu menunjuk seberapa berat akibatnya.
 *
 * KOLOM BARU NULLABLE, karena kejadian yang sudah tercatat sebelum ini
 * memang tidak punya jawabannya — dan mengisinya dengan tebakan lebih
 * buruk daripada mengakui tidak tahu. Grafik menampilkannya sebagai
 * "tidak tercatat", aturan yang sama seperti sumbu lain sejak item A.
 */
return new class extends Migration
{
    private const S = 'quality';

    public function up(): void
    {
        Schema::table(self::S.'.k3_incidents', function (Blueprint $table) {
            $table->string('wound_type', 100)->nullable()->after('injury_type')
                ->comment('Jenis luka (lecet, memar, patah) — berbeda dari injury_type yang menyebut jenis cideranya');
        });

        DB::statement('CREATE VIEW '.self::S.'.v_incident_summary AS
            SELECT id, report_number, occurred_at, incident_type, severity_band,
                   unit_id, location_detail, status, patient_id, registration_id
              FROM '.self::S.'.incident_reports');

        DB::statement('CREATE VIEW '.self::S.'.v_k3_incident AS
            SELECT id, incident_number, occurred_at, location, body_part,
                   injury_type, wound_type, injury_impact, job_type, cause, status
              FROM '.self::S.'.k3_incidents');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS '.self::S.'.v_k3_incident');
        DB::statement('DROP VIEW IF EXISTS '.self::S.'.v_incident_summary');

        Schema::table(self::S.'.k3_incidents', function (Blueprint $table) {
            $table->dropColumn('wound_type');
        });
    }
};
