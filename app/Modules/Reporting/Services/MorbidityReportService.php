<?php

namespace App\Modules\Reporting\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Morbiditas & surveilans penyakit (domain J item B).
 *
 * Lima belas kode efektif — sekali lagi potongan berbeda dari data yang
 * sama, yaitu diagnosis kunjungan:
 *
 *   frequency        -> penyakit_ralan, penyakit_ranap, penyakit (ICD 10)
 *   byTransmission   -> penyakit_menular_ralan/ranap,
 *                       pny_takmenular_ralan/ranap
 *   bySurveillance   -> penyakit_pd3i, surveilans_pd3i,
 *                       surveilans_ralan/ranap, kemenkes_sitt (TB)
 *   byPayer          -> penyakit_ranap_cara_bayar
 *   drugsForDisease  -> obat_penyakit
 *
 * Dua kode TNI/POLRI (laporan_penyakit_tni, laporan_penyakit_polri)
 * sengaja tidak digarap — lihat catatan roles.json.
 *
 * Yang perlu diingat saat membaca angkanya: diagnosis yang kodenya belum
 * ada di kamus tetap terhitung, dengan sifat penularan "tidak-diketahui".
 * Itu disengaja — menyembunyikannya akan membuat laporan tampak rapi
 * padahal ada kasus yang hilang.
 */
class MorbidityReportService
{
    private const DIAGNOSIS = 'clinical.v_encounter_diagnosis';
    private const REGISTRASI = 'encounter.v_registration_summary';
    private const SURVEILANS = 'clinical.v_diagnosis_surveillance_group';
    private const RESEP = 'pharmacy.v_prescription_charge';

    /** penyakit_ralan / penyakit_ranap — frekuensi penyakit terbanyak. */
    public function frequency(string $from, string $until, ?string $careType = null, int $limit = 50): Collection
    {
        return $this->diagnosisQuery($from, $until, $careType)
            ->groupBy('d.code', 'd.display', 'd.transmission')
            ->selectRaw('d.code, d.display, d.transmission, count(*) as jumlah, count(distinct d.patient_id) as pasien')
            ->orderByDesc('jumlah')
            ->limit($limit)
            ->get();
    }

    /**
     * penyakit_menular_* dan pny_takmenular_* — dua kode Khanza yang
     * sesungguhnya satu pengelompokan yang sama, dibedakan nilainya.
     */
    public function byTransmission(string $from, string $until, ?string $careType = null): Collection
    {
        return $this->diagnosisQuery($from, $until, $careType)
            ->groupBy('d.transmission')
            ->selectRaw('d.transmission, count(*) as jumlah, count(distinct d.patient_id) as pasien')
            ->orderByDesc('jumlah')
            ->get();
    }

    /** Rincian penyakit pada satu sifat penularan tertentu. */
    public function detailByTransmission(string $transmission, string $from, string $until, ?string $careType = null, int $limit = 50): Collection
    {
        return $this->diagnosisQuery($from, $until, $careType)
            ->where('d.transmission', $transmission)
            ->groupBy('d.code', 'd.display')
            ->selectRaw('d.code, d.display, count(*) as jumlah, count(distinct d.patient_id) as pasien')
            ->orderByDesc('jumlah')
            ->limit($limit)
            ->get();
    }

    /**
     * surveilans_pd3i, penyakit_pd3i, kemenkes_sitt — penyakit yang masuk
     * satu program surveilans tertentu.
     *
     * Keanggotaan dipakai sebagai PENYARING lewat subquery, bukan join,
     * supaya penyakit yang terdaftar di beberapa program tidak terhitung
     * berkali-kali.
     */
    public function bySurveillanceGroup(string $group, string $from, string $until, ?string $careType = null): Collection
    {
        return $this->diagnosisQuery($from, $until, $careType)
            ->whereIn('d.code', DB::table(self::SURVEILANS)->where('group', $group)->select('code'))
            ->groupBy('d.code', 'd.display')
            ->selectRaw('d.code, d.display, count(*) as jumlah, count(distinct d.patient_id) as pasien')
            ->orderByDesc('jumlah')
            ->get();
    }

    /** Daftar program surveilans yang punya anggota — supaya layar tidak menawarkan pilihan kosong. */
    public function surveillanceGroups(): Collection
    {
        return DB::table(self::SURVEILANS)
            ->groupBy('group')
            ->selectRaw('"group", count(*) as jumlah_kode')
            ->orderBy('group')
            ->get();
    }

    /** penyakit_ranap_cara_bayar — morbiditas dipilah menurut penjamin. */
    public function byPayer(string $from, string $until, ?string $careType = null): Collection
    {
        return $this->diagnosisQuery($from, $until, $careType, joinRegistrasi: true)
            ->groupBy('r.payer_name', 'd.transmission')
            ->selectRaw('r.payer_name, d.transmission, count(*) as jumlah')
            ->orderBy('r.payer_name')
            ->orderByDesc('jumlah')
            ->get();
    }

    /**
     * obat_penyakit — obat yang diserahkan pada kunjungan dengan diagnosis
     * tertentu. Dipakai memantau kesesuaian terapi terhadap diagnosis.
     */
    public function drugsForDisease(string $code, string $from, string $until, int $limit = 30): Collection
    {
        return DB::table(self::DIAGNOSIS . ' as d')
            ->join(self::RESEP . ' as p', 'p.registration_id', '=', 'd.registration_id')
            ->whereBetween(DB::raw('d.diagnosed_at::date'), [$from, $until])
            ->where('d.code', $code)
            ->groupBy('p.drug_name')
            ->selectRaw('p.drug_name, count(*) as jumlah, sum(p.dispensed_quantity) as jumlah_unit')
            ->orderByDesc('jumlah')
            ->limit($limit)
            ->get();
    }

    /**
     * Semua potongan lewat sini supaya definisi "diagnosis yang dihitung"
     * cuma ada di satu tempat — kalau ada yang tidak lewat, penyaringnya
     * akan diam-diam terabaikan.
     *
     * Diagnosis pada kunjungan yang dibatalkan tidak ikut: kontrak
     * v_registration_summary sudah membuang kunjungan batal, jadi
     * penyaring jenis rawat sekaligus menutup celah itu. Tanpa penyaring
     * jenis rawat, diagnosisnya dihitung apa adanya dari kontrak clinical.
     */
    private function diagnosisQuery(string $from, string $until, ?string $careType, bool $joinRegistrasi = false): Builder
    {
        $query = DB::table(self::DIAGNOSIS . ' as d')
            ->whereBetween(DB::raw('d.diagnosed_at::date'), [$from, $until]);

        if ($careType !== null || $joinRegistrasi) {
            $query->join(self::REGISTRASI . ' as r', 'r.id', '=', 'd.registration_id');
        }

        if ($careType !== null) {
            $query->where('r.care_type', $careType);
        }

        return $query;
    }
}
