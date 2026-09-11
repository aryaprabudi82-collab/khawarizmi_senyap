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
 *   surgicalSafety-     -> kepatuhan_kelengkapan_keselamatan_bedah
 *     Compliance
 *   advisoryRefusal-    -> laporan_tahunan_penolakan_anjuran_medis
 *     Yearly
 *
 * DUA YANG TERAKHIR DITAMBAHKAN SAAT VERIFIKASI DOMAIN J, dan keduanya
 * berasal dari kesalahan yang sama: layar ini menyatakan keduanya "belum
 * ada pencatatannya", dan kedua pernyataan itu sudah berhenti benar —
 * daftar tilik keselamatan bedah dibangun pada domain M item C, surat
 * penolakan pada domain P. Alasan yang benar saat ditulis tidak
 * memperbarui dirinya sendiri; yang membacanya berhenti mencari.
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

    private const KESELAMATAN_BEDAH = 'clinical.v_surgical_safety_compliance';

    private const PENOLAKAN = 'correspondence.v_advisory_refusal';

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

        /*
         * DIBACA DARI ADMISINYA, BUKAN DARI PASIENNYA. Sampai verifikasi
         * domain M, method ini menggabungkan admisi dengan
         * identity.v_patient_summary dan mengelompokkan menurut
         * `inpatient_classification` di sana — atribut pasien yang bisa
         * diubah kapan saja. Akibatnya rekap bulan lalu berubah sendiri
         * setiap kali seorang pasien dikategorikan ulang, dan tidak ada
         * yang terlihat salah: tidak ada baris yang hilang, totalnya tetap
         * cocok, cuma pembagiannya bergeser. Sekarang kategorinya dibekukan
         * pada admisi saat pasien masuk.
         */
        return DB::table(self::ADMISI.' as a')
            ->whereRaw('a.admitted_at::date BETWEEN ?::date AND ?::date', [$from, $until])
            ->groupBy(DB::raw($kolom), 'a.patient_category')
            ->selectRaw("{$kolom} AS kelompok,
                         coalesce(nullif(a.patient_category, ''), '(belum diklasifikasi)') AS klasifikasi,
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

        return DB::table(self::REGISTRASI.' as r')
            ->join(self::PASIEN.' as p', 'p.id', '=', 'r.patient_id')
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

    // ----------------------------------------------- keselamatan & penolakan

    /**
     * kepatuhan_kelengkapan_keselamatan_bedah — berapa persen operasi yang
     * daftar tilik keselamatan bedahnya lengkap ketiga fasenya.
     *
     * DITAMBAHKAN SAAT VERIFIKASI DOMAIN J. Kode ini semula dinyatakan
     * "belum ada pencatatannya" di layar, dan pernyataan itu benar sampai
     * domain M item C membangun clinical.surgical_safety_checklists.
     * Pernyataan yang basi membuat orang berhenti mencari.
     *
     * PENYEBUTNYA SELURUH OPERASI, bukan operasi yang punya daftar tilik.
     * Kepatuhan yang dihitung dari yang sudah diisi saja selalu mendekati
     * 100% dan tidak pernah menunjukkan masalah yang sebenarnya dicari
     * indikator ini: operasi yang daftar tiliknya tidak diisi sama sekali.
     *
     * "Bertemuan" ikut dilaporkan karena daftar tilik yang lengkap tapi
     * tidak pernah menemukan apa pun sepanjang tahun bukan kabar baik
     * melainkan tanda pengisiannya sekadar formalitas.
     */
    public function surgicalSafetyCompliance(string $from, string $until): object
    {
        $row = DB::table(self::KESELAMATAN_BEDAH)
            ->whereBetween(DB::raw('performed_at::date'), [$from, $until])
            ->selectRaw('count(*) AS operasi,
                         count(*) FILTER (WHERE fase_tercatat = 3) AS lengkap,
                         count(*) FILTER (WHERE fase_tercatat = 0) AS tanpa_daftar_tilik,
                         count(*) FILTER (WHERE sign_in = 0) AS tanpa_sign_in,
                         count(*) FILTER (WHERE time_out = 0) AS tanpa_time_out,
                         count(*) FILTER (WHERE sign_out = 0) AS tanpa_sign_out,
                         count(*) FILTER (WHERE fase_bertemuan > 0) AS bertemuan')
            ->first();

        $operasi = (int) ($row->operasi ?? 0);
        $lengkap = (int) ($row->lengkap ?? 0);

        return (object) [
            'operasi' => $operasi,
            'lengkap' => $lengkap,
            /*
             * Nol operasi berarti persentasenya TIDAK ADA, bukan nol
             * persen dan bukan seratus persen. Keduanya adalah pernyataan
             * tentang kepatuhan yang tidak pernah diukur.
             */
            'persen' => $operasi > 0 ? round($lengkap * 100 / $operasi, 1) : null,
            'tanpa_daftar_tilik' => (int) ($row->tanpa_daftar_tilik ?? 0),
            'tanpa_sign_in' => (int) ($row->tanpa_sign_in ?? 0),
            'tanpa_time_out' => (int) ($row->tanpa_time_out ?? 0),
            'tanpa_sign_out' => (int) ($row->tanpa_sign_out ?? 0),
            'bertemuan' => (int) ($row->bertemuan ?? 0),
        ];
    }

    /**
     * laporan_tahunan_penolakan_anjuran_medis — penolakan per bulan dalam
     * satu tahun, dipisah jenis suratnya.
     *
     * DITAMBAHKAN SAAT VERIFIKASI DOMAIN J. Datanya sudah ada sejak domain
     * P; yang belum ada cuma pembacanya.
     */
    public function advisoryRefusalYearly(int $year): Collection
    {
        return DB::table(self::PENOLAKAN)
            ->whereRaw('extract(year from signed_at) = ?', [$year])
            ->groupBy(DB::raw("to_char(signed_at, 'YYYY-MM')"), 'consent_type')
            ->selectRaw("to_char(signed_at, 'YYYY-MM') AS bulan,
                         consent_type,
                         count(*) AS penolakan,
                         count(distinct patient_id) AS pasien")
            ->orderBy('bulan')
            ->orderBy('consent_type')
            ->get();
    }
}
