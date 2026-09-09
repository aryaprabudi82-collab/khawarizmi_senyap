<?php

namespace App\Modules\Reporting\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Laporan RL Kemenkes (domain J item C) — 11 kode.
 *
 * PERINGATAN YANG HARUS IKUT TERBACA: yang dihasilkan di sini adalah
 * ANGKA yang mendasari tiap RL, bukan formulir RL siap kirim. Tata letak
 * baris/kolom resmi tiap formulir ditetapkan peraturan Kemenkes yang bisa
 * berubah, dan sebagian rincian yang diminta formulir belum tercatat di
 * sistem ini sama sekali (lihat catatan per method). Menyebutnya "RL siap
 * kirim" akan membuat orang mengirimkan angka yang belum diverifikasi.
 *
 *   bedAvailability   -> rl1_3_ketersediaan_kamar
 *   emergencyActivity -> rl32
 *   unitActivity      -> rl33 (gigi & mulut), rl34 (kebidanan)
 *   surgeryActivity   -> rl36
 *   supportActivity   -> rl37 (radiologi), rl38 (laboratorium)
 *   morbidity         -> rl4a (ranap), rl4b (ralan)
 *   morbidityByCause  -> rl4asebab, rl4bsebab
 *
 * RL 4A/4B seharusnya dikelompokkan menurut Daftar Tabulasi Dasar (DTD)
 * Kemenkes. Kolom dtd_group sudah disiapkan tapi sengaja dibiarkan kosong
 * sampai daftar resminya diimpor; selama itu pengelompokan memakai bab
 * ICD-10 dan hal itu dinyatakan terang-terangan, bukan disamarkan.
 */
class StatutoryReportService
{
    private const DIAGNOSIS = 'clinical.v_encounter_diagnosis';

    private const REGISTRASI = 'encounter.v_registration_summary';

    private const PASIEN = 'identity.v_patient_summary';

    private const TRIASE = 'encounter.v_triage_summary';

    private const BED = 'inpatient.v_bed_availability';

    private const OPERASI = 'clinical.v_operation_summary';

    private const KAMUS = 'clinical.v_diagnosis_code';

    private const ORDER = 'orders.v_order_summary';

    /** RL 1.3 — kapasitas tempat tidur per kelas perawatan. */
    public function bedAvailability(): Collection
    {
        return DB::table(self::BED)
            ->groupBy('room_class')
            ->selectRaw("room_class,
                         sum(jumlah) as total,
                         sum(case when status = 'terisi' then jumlah else 0 end) as terisi,
                         sum(case when status = 'tersedia' then jumlah else 0 end) as tersedia")
            ->orderBy('room_class')
            ->get();
    }

    /** RL 3.2 — kunjungan gawat darurat menurut tingkat triase. */
    public function emergencyActivity(string $from, string $until): Collection
    {
        return DB::table(self::TRIASE)
            ->whereBetween('service_date', [$from, $until])
            ->groupBy('triage_level')
            ->selectRaw('triage_level, count(*) as jumlah, count(distinct patient_id) as pasien')
            ->orderBy('triage_level')
            ->get();
    }

    /**
     * RL 3.3 & RL 3.4 — kegiatan pelayanan pada satu unit.
     *
     * Yang dihitung kunjungan dan tindakan di unit itu. Formulir resmi
     * RL 3.3 merinci jenis tindakan gigi (tumpatan, pencabutan, dan
     * seterusnya) dan RL 3.4 merinci jenis persalinan berikut sebab
     * kematian ibu/bayi — rincian sedetail itu BELUM dicatat di mana pun,
     * jadi yang tersaji di sini baru volume kegiatannya.
     */
    public function unitActivity(string $unitName, string $from, string $until): Collection
    {
        return DB::table(self::REGISTRASI)
            ->whereBetween('service_date', [$from, $until])
            ->where('unit_name', $unitName)
            ->groupBy('care_type')
            ->selectRaw('care_type, count(*) as kunjungan, count(distinct patient_id) as pasien')
            ->orderBy('care_type')
            ->get();
    }

    /** RL 3.6 — kegiatan pembedahan menurut jenis anestesi dan kamar operasi. */
    public function surgeryActivity(string $from, string $until): Collection
    {
        /*
         * DIKELOMPOKKAN MENURUT KODE RUANG, DINAMAI LEWAT MASTERNYA.
         *
         * Sebelumnya `operating_room` adalah teks bebas yang diketik di DUA
         * konteks — saat menjadwalkan operasi dan saat mencatat laporannya —
         * dan pengelompokan di bawah ini memakai teks itu apa adanya. "OK 1"
         * dan "OK1" karena itu terhitung sebagai dua kamar operasi berbeda
         * pada laporan wajib yang dikirim ke Kemenkes, dan tidak ada galat
         * yang muncul: angkanya tetap tampak wajar, hanya saja utilisasi
         * satu ruang terbelah jadi dua baris.
         *
         * Sekarang nilainya kode dari master `organization.operating_rooms`,
         * dan namanya dibaca lewat view yang diterbitkan konteks itu —
         * bukan disalin, supaya ruang yang berganti nama langsung terbaca
         * dengan nama barunya di seluruh laporan.
         */
        return DB::table(self::OPERASI.' as o')
            ->leftJoin('organization.v_operating_room as r', 'r.code', '=', 'o.operating_room')
            ->whereBetween(DB::raw('o.performed_at::date'), [$from, $until])
            ->groupBy('o.anesthesia_type', 'o.operating_room', 'r.name')
            ->selectRaw("coalesce(o.anesthesia_type, '—') as anesthesia_type,
                         coalesce(r.name, o.operating_room, '—') as operating_room,
                         count(*) as jumlah, count(distinct o.patient_id) as pasien")
            ->orderByDesc('jumlah')
            ->get();
    }

    /** RL 3.7 & RL 3.8 — kegiatan radiologi dan laboratorium. */
    public function supportActivity(string $category, string $from, string $until): Collection
    {
        return DB::table(self::ORDER.' as o')
            ->join(self::REGISTRASI.' as r', 'r.id', '=', 'o.registration_id')
            ->whereBetween(DB::raw('o.requested_at::date'), [$from, $until])
            ->where('o.category', $category)
            ->groupBy('r.care_type', 'o.status')
            ->selectRaw('r.care_type, o.status, count(*) as jumlah')
            ->orderBy('r.care_type')
            ->orderBy('o.status')
            ->get();
    }

    /**
     * RL 4A / RL 4B — morbiditas menurut kelompok sebab, golongan umur,
     * dan jenis kelamin.
     *
     * Golongan umurnya mengikuti pembagian yang dipakai formulir RL 4.
     */
    public function morbidity(string $careType, string $from, string $until, int $limit = 100): Collection
    {
        $umur = "date_part('year', age(d.diagnosed_at, p.birth_date))";

        return $this->morbidityQuery($careType, $from, $until)
            ->join(self::PASIEN.' as p', 'p.id', '=', 'd.patient_id')
            ->groupBy('d.code', 'd.display', DB::raw($this->rl4AgeGroup($umur)), 'p.sex')
            ->selectRaw('d.code, d.display, '.$this->rl4AgeGroup($umur).' as golongan_umur, p.sex, count(*) as jumlah')
            ->orderByDesc('jumlah')
            ->limit($limit)
            ->get();
    }

    /**
     * RL 4A Sebab / RL 4B Sebab — dikelompokkan menurut kelompok sebab.
     *
     * Memakai DTD kalau sudah diimpor; kalau belum, memakai bab ICD-10.
     * Yang dipakai dilaporkan lewat usingDtd() supaya layarnya bisa
     * menyatakannya, bukan diam-diam menyamar sebagai DTD.
     */
    public function morbidityByCause(string $careType, string $from, string $until): Collection
    {
        $kolom = $this->usingDtd() ? 'd.dtd_group' : 'd.chapter';

        return $this->morbidityQuery($careType, $from, $until)
            ->groupBy(DB::raw($kolom))
            ->selectRaw("coalesce({$kolom}, '— belum terklasifikasi —') as kelompok,
                         count(*) as jumlah, count(distinct d.patient_id) as pasien")
            ->orderByDesc('jumlah')
            ->get();
    }

    /** Apakah daftar DTD resmi sudah diimpor. Dipakai layar untuk menyatakan dasar pengelompokannya. */
    public function usingDtd(): bool
    {
        return DB::table(self::KAMUS)->whereNotNull('dtd_group')->exists();
    }

    /** Berapa kode diagnosis yang belum punya kelompok DTD — angka yang harus terlihat sebelum RL 4 dikirim. */
    public function unclassifiedDtdCount(): int
    {
        return DB::table(self::KAMUS)->whereNull('dtd_group')->count();
    }

    private function morbidityQuery(string $careType, string $from, string $until)
    {
        return DB::table(self::DIAGNOSIS.' as d')
            ->join(self::REGISTRASI.' as r', 'r.id', '=', 'd.registration_id')
            ->whereBetween(DB::raw('d.diagnosed_at::date'), [$from, $until])
            ->where('r.care_type', $careType);
    }

    /** Golongan umur formulir RL 4. */
    private function rl4AgeGroup(string $umur): string
    {
        return "CASE
            WHEN {$umur} < 1 THEN '0-6 hari s.d. <1 th'
            WHEN {$umur} < 5 THEN '1-4 th'
            WHEN {$umur} < 15 THEN '5-14 th'
            WHEN {$umur} < 25 THEN '15-24 th'
            WHEN {$umur} < 45 THEN '25-44 th'
            WHEN {$umur} < 65 THEN '45-64 th'
            ELSE '65+ th'
        END";
    }
}
