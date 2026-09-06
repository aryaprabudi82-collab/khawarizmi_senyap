<?php

namespace App\Modules\Reporting\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Penunjang, gizi, skrining & sasaran usia (domain J item E) — sisa kode
 * domain J di luar HAIs, yang dibangun tersendiri di konteks quality.
 *
 *   ancillaryYearly     -> rekap_lab_pertahun, rekap_radiologi_pertahun
 *   ancillaryReferrers  -> perujuk_lab_pertahun, perujuk_radiologi_pertahun
 *   surgeryMonthly      -> operasi_per_bulan
 *   dietRecap           -> rekap_permintaan_diet, jumlah_macam_diet,
 *                          jumlah_porsi_diet
 *   respiratoryScreening-> skrining_ralan_pernapasan_pertahun
 *   inpatientClass      -> harian/bulanan/perbangsal klasifikasi_pasien_ranap
 *   ageTargets          -> data_sasaran_usiaproduktif, data_sasaran_usialansia
 *
 * Porsi gizi dihitung sebagai HARI-DIET, bukan jumlah baris permintaan.
 * Satu permintaan diet lima hari adalah lima hari pemberian; menghitungnya
 * sebagai satu akan membuat angka gizi jauh lebih kecil daripada
 * kenyataannya tanpa terlihat salah.
 */
class AncillaryReportService
{
    private const ORDER = 'orders.v_order_summary';
    private const REGISTRASI = 'encounter.v_registration_summary';
    private const PASIEN = 'identity.v_patient_summary';
    private const OPERASI = 'clinical.v_operation_summary';
    private const DIET = 'inpatient.v_diet_order';
    private const ADMISI = 'inpatient.v_admission_summary';
    private const SKRINING = 'clinical.v_screening_summary';

    // ------------------------------------------------------------- penunjang

    /** Rekap permintaan penunjang per bulan dalam satu tahun. */
    public function ancillaryYearly(string $category, int $year): Collection
    {
        return DB::table(self::ORDER)
            ->where('category', $category)
            ->whereRaw('extract(year from requested_at) = ?', [$year])
            ->groupBy(DB::raw("to_char(requested_at, 'YYYY-MM')"))
            ->selectRaw("to_char(requested_at, 'YYYY-MM') AS bulan,
                         count(*) AS permintaan,
                         count(distinct patient_id) AS pasien,
                         count(*) FILTER (WHERE verified_at IS NOT NULL) AS terverifikasi")
            ->orderBy('bulan')
            ->get();
    }

    /**
     * Perujuk penunjang dalam satu tahun.
     *
     * Dokter perujuk yang tidak tercatat dihitung sebagai "(tidak
     * tercatat)", bukan dibuang — permintaan tanpa perujuk tetap
     * permintaan, dan membuangnya membuat total tidak cocok dengan
     * rekap tahunan di sebelahnya.
     */
    public function ancillaryReferrers(string $category, int $year): Collection
    {
        return DB::table(self::ORDER)
            ->where('category', $category)
            ->whereRaw('extract(year from requested_at) = ?', [$year])
            ->groupBy('requesting_practitioner_name')
            ->selectRaw("coalesce(requesting_practitioner_name, '(tidak tercatat)') AS perujuk,
                         count(*) AS permintaan,
                         count(distinct patient_id) AS pasien")
            ->orderByDesc('permintaan')
            ->get();
    }

    /** Kegiatan pembedahan per bulan dalam satu tahun. */
    public function surgeryMonthly(int $year): Collection
    {
        return DB::table(self::OPERASI)
            ->whereRaw('extract(year from performed_at) = ?', [$year])
            ->groupBy(DB::raw("to_char(performed_at, 'YYYY-MM')"))
            ->selectRaw("to_char(performed_at, 'YYYY-MM') AS bulan,
                         count(*) AS tindakan,
                         count(distinct patient_id) AS pasien")
            ->orderBy('bulan')
            ->get();
    }

    // ------------------------------------------------------------------ gizi

    /**
     * Rekap gizi: macam diet dan hari-diet (porsi) pada satu rentang.
     *
     * Yang dihitung irisan permintaan diet dengan rentangnya, bukan
     * permintaan yang mulai di rentang itu — diet yang berjalan melintasi
     * batas bulan tetap dimasak setiap harinya.
     */
    public function dietRecap(string $from, string $until): Collection
    {
        return DB::table(self::DIET)
            ->whereRaw('start_date <= ?::date', [$until])
            ->whereRaw('coalesce(end_date, current_date) >= ?::date', [$from])
            ->groupBy('diet_type')
            ->selectRaw('diet_type,
                         count(*) AS permintaan,
                         coalesce(sum(
                             least(coalesce(end_date, current_date), ?::date)
                             - greatest(start_date, ?::date) + 1
                         ), 0) AS hari_diet', [$until, $from])
            ->orderByDesc('hari_diet')
            ->get();
    }

    // -------------------------------------------------------------- skrining

    /**
     * Skrining pernapasan rawat jalan per bulan.
     *
     * Yang dihitung skrining dengan gejala infeksius tercatat, berdampingan
     * dengan total skrining — proporsi tanpa penyebutnya tidak berarti apa-apa.
     */
    public function respiratoryScreening(int $year): Collection
    {
        return DB::table(self::SKRINING)
            ->whereRaw('extract(year from screened_at) = ?', [$year])
            ->groupBy(DB::raw("to_char(screened_at, 'YYYY-MM')"))
            ->selectRaw("to_char(screened_at, 'YYYY-MM') AS bulan,
                         count(*) AS skrining,
                         count(*) FILTER (WHERE infectious_symptom IS TRUE) AS bergejala")
            ->orderBy('bulan')
            ->get();
    }

    // ------------------------------------------- klasifikasi & sasaran usia

    /**
     * Klasifikasi pasien rawat inap, dikelompokkan harian/bulanan/per bangsal.
     *
     * Pasien yang klasifikasinya belum diisi muncul sebagai "(belum
     * diklasifikasi)" berikut jumlahnya — kalau dibuang, laporan tampak
     * lengkap padahal sebagian besar pasien tidak terwakili.
     */
    public function inpatientClass(string $groupBy, string $from, string $until): Collection
    {
        $kolom = match ($groupBy) {
            'harian' => "to_char(a.admitted_at, 'YYYY-MM-DD')",
            'bulanan' => "to_char(a.admitted_at, 'YYYY-MM')",
            // Kamar yang belum tertaut unit organisasi diberi label
            // sendiri, bukan dibiarkan NULL — baris tanpa nama bangsal akan
            // tampak seperti kekeliruan sistem, padahal yang kurang adalah
            // penautan kamarnya.
            'bangsal' => "coalesce(a.room_unit_name, '(bangsal belum ditautkan)')",
            default => "to_char(a.admitted_at, 'YYYY-MM')",
        };

        return DB::table(self::ADMISI . ' as a')
            ->join(self::PASIEN . ' as p', 'p.id', '=', 'a.patient_id')
            ->whereRaw('a.admitted_at::date BETWEEN ?::date AND ?::date', [$from, $until])
            ->groupBy(DB::raw($kolom), 'p.inpatient_classification')
            ->selectRaw("{$kolom} AS kelompok,
                         coalesce(nullif(p.inpatient_classification, ''), '(belum diklasifikasi)') AS klasifikasi,
                         count(*) AS jumlah")
            ->orderBy('kelompok')
            ->orderBy('klasifikasi')
            ->get();
    }

    /**
     * Sasaran usia produktif (15-59) dan lansia (60+).
     *
     * Golongan usianya mengikuti pembagian program Kemenkes; umurnya
     * dihitung pada tanggal kunjungan, bukan hari ini, supaya laporan
     * tahun lalu tidak berubah seiring pasiennya bertambah tua.
     */
    public function ageTargets(string $from, string $until): Collection
    {
        $umur = "date_part('year', age(r.service_date::timestamp, p.birth_date))";

        return DB::table(self::REGISTRASI . ' as r')
            ->join(self::PASIEN . ' as p', 'p.id', '=', 'r.patient_id')
            ->whereBetween('r.service_date', [$from, $until])
            ->groupBy(DB::raw("CASE WHEN {$umur} BETWEEN 15 AND 59 THEN 'usia-produktif'
                                    WHEN {$umur} >= 60 THEN 'lansia'
                                    ELSE 'di luar sasaran' END"), 'p.sex')
            ->selectRaw("CASE WHEN {$umur} BETWEEN 15 AND 59 THEN 'usia-produktif'
                              WHEN {$umur} >= 60 THEN 'lansia'
                              ELSE 'di luar sasaran' END AS sasaran,
                         p.sex,
                         count(*) AS kunjungan,
                         count(distinct r.patient_id) AS pasien")
            ->orderBy('sasaran')
            ->orderBy('p.sex')
            ->get();
    }
}
