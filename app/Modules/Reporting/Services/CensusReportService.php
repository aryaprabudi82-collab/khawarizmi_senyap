<?php

namespace App\Modules\Reporting\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sensus & kunjungan (domain J item A).
 *
 * Dua puluh satu kode laporan Khanza yang, seperti pola yang sudah
 * berulang di domain I, sesungguhnya potongan berbeda dari data yang sama:
 * registrasi kunjungan, admisi rawat inap, dan permintaan penunjang.
 * Jadi bukan dua puluh satu layar, melainkan satu layar berpenyaring.
 *
 *   dailyCensus        -> sensus_harian_poli, sensus_harian_ralan,
 *                         kunjungan_ralan, kunjungan_ranap
 *   byUnit             -> registrasi_poli_per_tanggal, sensus_harian_poli
 *   byPractitioner     -> kunjungan per dokter
 *   arrivalByHour      -> kedatangan_pasien (per jam)
 *   byAgeGroup         -> demografi_umur_kunjungan
 *   cancelled          -> pembatalan_periksa_dokter, cek_entry_ralan
 *   monthly / annual   -> laporan_bulanan_irj, laporan_tahunan_irj,
 *                         laporan_tahunan_igd
 *   admissions*        -> daftar_pasien_ranap, ranap_per_ruang,
 *                         poli_asal_pasien_ranap, dokter_asal_pasien_ranap,
 *                         kunjungan_bangsal_pertahun
 *   supportOrders      -> kunjungan_permintaan_lab / lab2 /
 *                         radiologi / radiologi2
 *
 * Semuanya dibaca lewat kontrak terbitan konteks lain — encounter,
 * identity, inpatient, orders — bukan dari tabelnya langsung.
 *
 * Registrasi yang dibatalkan sengaja DIKECUALIKAN dari hitungan kunjungan,
 * dan dihitung tersendiri lewat cancelled(): kunjungan yang batal bukan
 * kunjungan, tapi jumlahnya sendiri adalah informasi yang dicari
 * (pembatalan_periksa_dokter).
 */
class CensusReportService
{
    private const REGISTRASI = 'encounter.v_registration_summary';
    private const PASIEN = 'identity.v_patient_summary';
    private const ADMISI = 'inpatient.v_admission_summary';
    private const ORDER = 'orders.v_order_summary';
    private const PEMBATALAN = 'encounter.v_registration_cancellation';

    /** Sensus harian: kunjungan per tanggal, dipisah jenis rawat. */
    public function dailyCensus(string $from, string $until, ?string $careType = null, ?int $unitId = null): Collection
    {
        return $this->visitQuery($from, $until, $careType, $unitId)
            ->groupBy('service_date', 'care_type')
            ->selectRaw('service_date, care_type, count(*) as jumlah')
            ->orderBy('service_date')
            ->orderBy('care_type')
            ->get();
    }

    /** Kunjungan per unit/poliklinik. */
    public function byUnit(string $from, string $until, ?string $careType = null): Collection
    {
        return $this->visitQuery($from, $until, $careType)
            ->groupBy('unit_name')
            ->selectRaw("coalesce(unit_name, '—') as unit_name, count(*) as jumlah, count(distinct patient_id) as pasien")
            ->orderByDesc('jumlah')
            ->get();
    }

    /** Kunjungan per dokter. */
    public function byPractitioner(string $from, string $until, ?string $careType = null, ?int $unitId = null): Collection
    {
        return $this->visitQuery($from, $until, $careType, $unitId)
            ->whereNotNull('practitioner_id')
            ->groupBy('practitioner_id', 'practitioner_name')
            ->selectRaw('practitioner_id, practitioner_name, count(*) as jumlah, count(distinct patient_id) as pasien')
            ->orderByDesc('jumlah')
            ->get();
    }

    /** Kunjungan per penjamin. */
    public function byPayer(string $from, string $until, ?string $careType = null, ?int $unitId = null): Collection
    {
        return $this->visitQuery($from, $until, $careType, $unitId)
            ->groupBy('payer_name')
            ->selectRaw('payer_name, count(*) as jumlah')
            ->orderByDesc('jumlah')
            ->get();
    }

    /**
     * kedatangan_pasien — sebaran jam kedatangan, dasar penentuan jam
     * sibuk loket dan poliklinik.
     */
    public function arrivalByHour(string $from, string $until, ?string $careType = null, ?int $unitId = null): Collection
    {
        return $this->visitQuery($from, $until, $careType, $unitId)
            ->groupBy(DB::raw('extract(hour from registered_at)'))
            ->selectRaw('extract(hour from registered_at) as jam, count(*) as jumlah')
            ->orderBy('jam')
            ->get();
    }

    /**
     * demografi_umur_kunjungan — kelompok umur mengikuti pembagian yang
     * lazim dipakai laporan Kemenkes, bukan rentang sembarang.
     */
    public function byAgeGroup(string $from, string $until, ?string $careType = null, ?int $unitId = null): Collection
    {
        $umur = "date_part('year', age(r.service_date, p.birth_date))";

        return $this->visitQuery($from, $until, $careType, $unitId, alias: 'r')
            ->join(self::PASIEN . ' as p', 'p.id', '=', 'r.patient_id')
            ->whereNotNull('p.birth_date')
            ->groupBy(DB::raw($this->ageGroupExpression($umur)))
            ->selectRaw($this->ageGroupExpression($umur) . ' as kelompok, count(*) as jumlah')
            ->orderByRaw('min(' . $umur . ')')
            ->get();
    }

    /**
     * pembatalan_periksa_dokter.
     *
     * Dibaca dari kontrak TERPISAH, bukan dari v_registration_summary:
     * kontrak utama itu sengaja membuang kunjungan batal supaya billing
     * dan klinis tidak pernah menindaklanjutinya, dan invarian itu tidak
     * boleh dilonggarkan hanya demi satu laporan.
     */
    public function cancelled(string $from, string $until, ?int $unitId = null): Collection
    {
        $query = DB::table(self::PEMBATALAN)
            ->whereBetween('service_date', [$from, $until]);

        if ($unitId !== null) {
            $query->where('unit_id', $unitId);
        }

        return $query
            ->groupBy('unit_name', 'practitioner_name')
            ->selectRaw("coalesce(unit_name, '—') as unit_name, coalesce(practitioner_name, '—') as practitioner_name, count(*) as jumlah")
            ->orderByDesc('jumlah')
            ->get();
    }

    /** laporan_bulanan_irj — rekap per bulan pada satu tahun. */
    public function monthly(int $year, ?string $careType = null, ?int $unitId = null): Collection
    {
        return $this->visitQuery($year . '-01-01', $year . '-12-31', $careType, $unitId)
            ->groupBy(DB::raw('extract(month from service_date)'))
            ->selectRaw('extract(month from service_date) as bulan, count(*) as jumlah, count(distinct patient_id) as pasien')
            ->orderBy('bulan')
            ->get();
    }

    /** daftar_pasien_ranap — pasien yang sedang atau pernah dirawat pada rentang tanggal. */
    public function admissions(string $from, string $until, ?string $roomClass = null): Collection
    {
        return $this->admissionQuery($from, $until, $roomClass)
            ->selectRaw('admission_number, patient_mrn, patient_name, room_number, room_class, bed_number,
                         dpjp_name, admitted_at, discharged_at, status')
            ->orderByDesc('admitted_at')
            ->limit(500)
            ->get();
    }

    /** ranap_per_ruang — jumlah admisi per ruang/kelas. */
    public function admissionsByRoom(string $from, string $until, ?string $roomClass = null): Collection
    {
        return $this->admissionQuery($from, $until, $roomClass)
            ->groupBy('room_number', 'room_class')
            ->selectRaw('room_number, room_class, count(*) as jumlah')
            ->orderByDesc('jumlah')
            ->get();
    }

    /**
     * poli_asal_pasien_ranap & dokter_asal_pasien_ranap — dari poli dan
     * dokter mana pasien rawat inap ini berasal. Disambungkan lewat
     * registrasi yang sama, karena admisi memang berdiri di atas
     * registrasi ranap.
     */
    public function admissionOrigin(string $from, string $until, string $by = 'unit'): Collection
    {
        $kolom = $by === 'dokter' ? 'r.practitioner_name' : 'r.unit_name';

        return DB::table(self::ADMISI . ' as a')
            ->join(self::REGISTRASI . ' as r', 'r.id', '=', 'a.registration_id')
            ->whereBetween(DB::raw('a.admitted_at::date'), [$from, $until])
            ->groupBy(DB::raw($kolom))
            ->selectRaw("coalesce({$kolom}, '—') as asal, count(*) as jumlah")
            ->orderByDesc('jumlah')
            ->get();
    }

    /**
     * kunjungan_permintaan_lab / lab2 / radiologi / radiologi2 —
     * permintaan penunjang, dipisah kategori dan jenis rawat.
     */
    public function supportOrders(string $from, string $until, ?string $category = null, ?string $careType = null): Collection
    {
        $query = DB::table(self::ORDER . ' as o')
            ->whereBetween(DB::raw('o.requested_at::date'), [$from, $until]);

        if ($category !== null) {
            $query->where('o.category', $category);
        }

        if ($careType !== null) {
            $query->join(self::REGISTRASI . ' as r', 'r.id', '=', 'o.registration_id')
                ->where('r.care_type', $careType);
        }

        return $query
            ->groupBy('o.category', 'o.status')
            ->selectRaw('o.category, o.status, count(*) as jumlah')
            ->orderBy('o.category')
            ->orderBy('o.status')
            ->get();
    }

    /**
     * Kunjungan yang sah dihitung.
     *
     * Yang dibatalkan tidak perlu disaring di sini: kontrak
     * v_registration_summary sudah membuangnya di sisi encounter, dan
     * satu tempat yang memegang aturan itu sudah cukup. Semua method di
     * atas lewat sini supaya definisi kunjungan cuma satu — kalau ada
     * yang tidak lewat, penyaringnya akan diam-diam terabaikan.
     */
    private function visitQuery(string $from, string $until, ?string $careType, ?int $unitId = null, string $alias = ''): Builder
    {
        $tabel = $alias === '' ? self::REGISTRASI : self::REGISTRASI . ' as ' . $alias;
        $p = $alias === '' ? '' : $alias . '.';

        $query = DB::table($tabel)
            ->whereBetween($p . 'service_date', [$from, $until]);

        if ($careType !== null) {
            $query->where($p . 'care_type', $careType);
        }

        if ($unitId !== null) {
            $query->where($p . 'unit_id', $unitId);
        }

        return $query;
    }

    private function admissionQuery(string $from, string $until, ?string $roomClass): Builder
    {
        $query = DB::table(self::ADMISI)
            ->whereBetween(DB::raw('admitted_at::date'), [$from, $until]);

        if ($roomClass !== null) {
            $query->where('room_class', $roomClass);
        }

        return $query;
    }

    /** Kelompok umur laporan Kemenkes; dipakai sama persis untuk group by dan select. */
    private function ageGroupExpression(string $umur): string
    {
        return "CASE
            WHEN {$umur} < 1 THEN '0-<1 th'
            WHEN {$umur} < 5 THEN '1-4 th'
            WHEN {$umur} < 15 THEN '5-14 th'
            WHEN {$umur} < 25 THEN '15-24 th'
            WHEN {$umur} < 45 THEN '25-44 th'
            WHEN {$umur} < 65 THEN '45-64 th'
            ELSE '65+ th'
        END";
    }
}
